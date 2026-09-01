use axum::{
    body::Body,
    extract::State,
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::{get, post},
    Json, Router,
};
use serde::Serialize;
use futures_util::StreamExt;
use std::sync::Arc;
use tokio::{sync::Semaphore, time::{timeout, Duration}};

use crate::AppState;
use alpha_common::ChatRequest;
use alpha_core::router::{Intent, IntentRouter};

pub fn create_router(state: AppState) -> Router {
    Router::new()
        .route("/v1/chat/completions", post(chat_completions))
        .route("/v1/models", get(models))
        .route("/health", get(health))
        .route("/status", get(status))
        .route("/workers", get(workers))
        .with_state(state)
}

async fn chat_completions(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(mut request): Json<ChatRequest>,
) -> Response {
    if request.mode.is_none() {
        request.mode = headers
            .get("x-ashat-mode")
            .and_then(|value| value.to_str().ok())
            .map(str::to_owned);
    }
    // Wire the wait queue: full -> 429, else enqueue and wait for a concurrency slot.
    let request_id = format!(
        "req-{}",
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_nanos())
            .unwrap_or(0)
    );
    {
        let mut queue = state.queue.write().await;
        if queue.enqueue(request_id).is_err() {
            return (
                StatusCode::TOO_MANY_REQUESTS,
                Json(serde_json::json!({
                    "error": "Queue full",
                    "message": "Maximum requests in queue reached"
                })),
            ).into_response();
        }
    }
    // Classify intent — image detection + "model": "local" bypass.
    let intent = if request.model.as_deref() == Some("local") {
        // "model": "local" from AshatHub — check for images.
        IntentRouter::classify_local(&request.messages)
    } else {
        state.router.classify(&request.messages, request.stream, request.mode.as_deref())
    };

    tracing::info!(
        "Classified intent: {:?} ({} messages)",
        intent,
        request.messages.len()
    );

    let _account_permit = if let Some(account) = headers.get("x-ashat-account").and_then(|value| value.to_str().ok()).filter(|value| !value.is_empty()) {
        let slots = {
            let mut accounts = state.account_slots.lock().await;
            accounts.entry(account.to_owned()).or_insert_with(|| Arc::new(Semaphore::new(2))).clone()
        };
        match timeout(Duration::from_secs(30), slots.acquire_owned()).await {
            Ok(Ok(permit)) => Some(permit),
            _ => {
                let mut queue = state.queue.write().await;
                queue.dequeue();
                return completion_response(request.stream, StatusCode::TOO_MANY_REQUESTS, serde_json::json!({
                    "error": "Account inference limit reached",
                    "message": "This account has too many active requests; retry shortly"
                }));
            }
        }
    } else { None };

    let capacity = match intent {
        Intent::Vision => state.vision_slots.clone(),
        Intent::LocalInference => state.text_slots.clone(),
        Intent::ChatStudio | Intent::FileGeneration => state.agent_slots.clone(),
        Intent::Unknown => {
            let mut queue = state.queue.write().await;
            queue.dequeue();
            return completion_response(request.stream, StatusCode::BAD_REQUEST, serde_json::json!({
                "error": "Unknown intent",
                "message": "Could not classify the request"
            }));
        }
    };
    let _permit = match timeout(Duration::from_secs(30), capacity.acquire_owned()).await {
        Ok(Ok(permit)) => permit,
        Ok(Err(error)) => {
            let mut queue = state.queue.write().await;
            queue.dequeue();
            tracing::error!(?error, "inference capacity unavailable");
            return completion_response(request.stream, StatusCode::SERVICE_UNAVAILABLE, serde_json::json!({
                "error": "Inference capacity unavailable"
            }));
        }
        Err(_) => {
            let mut queue = state.queue.write().await;
            queue.dequeue();
            return completion_response(request.stream, StatusCode::TOO_MANY_REQUESTS, serde_json::json!({
                "error": "Inference queue wait expired",
                "message": "Capacity is busy; retry shortly"
            }));
        }
    };
    {
        let mut queue = state.queue.write().await;
        queue.dequeue();
    }

    if request.stream {
        match intent {
            Intent::LocalInference => return match alpha_core::proxy::text_worker_stream(&state.text_worker, &request).await {
                Ok(response) => upstream_stream_response(response),
                Err(error) => completion_response(true, StatusCode::BAD_GATEWAY, serde_json::json!({"error": error.to_string()})),
            },
            Intent::Vision => return match alpha_core::proxy::vision_stream(&state.vision_pool, &request).await {
                Ok((port, response)) => vision_stream_response(state.vision_pool.clone(), port, response),
                Err(error) => completion_response(true, StatusCode::BAD_GATEWAY, serde_json::json!({"error": error.to_string()})),
            },
            _ => {}
        }
    }

    match intent {
        // ── Vision: on-demand 450M VL ──────────────────────────
        Intent::Vision => {
            match alpha_core::proxy::vision_completions(&state.vision_pool, &request).await {
                Ok(body) => completion_response(request.stream, StatusCode::OK, body),
                Err(e) => {
                    tracing::error!("Vision inference failed: {}", e);
                    completion_response(request.stream, StatusCode::BAD_GATEWAY, serde_json::json!({
                        "error": "Vision inference failed",
                        "message": e.to_string()
                    }))
                }
            }
        }

        // ── Text: always-on 350M text worker ───────────────────
        Intent::LocalInference => {
            match alpha_core::proxy::text_worker_completions(&state.text_worker, &request).await {
                Ok(body) => completion_response(request.stream, StatusCode::OK, body),
                Err(e) => {
                    tracing::error!("Text worker inference failed: {}", e);
                    completion_response(request.stream, StatusCode::BAD_GATEWAY, serde_json::json!({
                        "error": "Text worker inference failed",
                        "message": e.to_string()
                    }))
                }
            }
        }

        // ── Coding: Omega/Beta/Delta ───────────────────────────
        Intent::ChatStudio | Intent::FileGeneration => {
            let remote = alpha_core::inference::RemoteInference::new(state.router.config().clone());
            match remote.infer(&request).await {
                Ok(response) => completion_response(request.stream, StatusCode::OK, serde_json::to_value(response).unwrap_or_default()),
                Err(e) => {
                    tracing::error!("Omega inference failed: {}", e);
                    completion_response(request.stream, StatusCode::BAD_GATEWAY, serde_json::json!({
                        "error": "Inference failed",
                        "message": e.to_string()
                    }))
                }
            }
        }

        Intent::Unknown => completion_response(request.stream, StatusCode::BAD_REQUEST, serde_json::json!({
            "error": "Unknown intent",
            "message": "Could not classify the request"
        })),
    }
}

fn completion_response(stream: bool, status: StatusCode, body: serde_json::Value) -> Response {
    if !stream {
        return (status, Json(body)).into_response();
    }

    let content = body
        .pointer("/choices/0/message/content")
        .and_then(serde_json::Value::as_str)
        .unwrap_or_default();
    let id = format!(
        "chatcmpl-{}",
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|value| value.as_nanos())
            .unwrap_or_default()
    );
    let chunk = serde_json::json!({
        "id": id,
        "object": "chat.completion.chunk",
        "choices": [{"index": 0, "delta": {"role": "assistant", "content": content}, "finish_reason": null}]
    });
    let done = serde_json::json!({
        "id": id,
        "object": "chat.completion.chunk",
        "choices": [{"index": 0, "delta": {}, "finish_reason": "stop"}]
    });
    let payload = format!("data: {chunk}\n\ndata: {done}\n\ndata: [DONE]\n\n");
    (
        status,
        [(header::CONTENT_TYPE, "text/event-stream"), (header::CACHE_CONTROL, "no-cache")],
        payload,
    ).into_response()
}

fn upstream_stream_response(response: reqwest::Response) -> Response {
    let stream = response.bytes_stream().map(|chunk| chunk.map_err(axum::Error::new));
    (StatusCode::OK, [(header::CONTENT_TYPE, "text/event-stream"), (header::CACHE_CONTROL, "no-cache")], Body::from_stream(stream)).into_response()
}

fn vision_stream_response(pool: Arc<alpha_core::demand::VisionPool>, port: u16, response: reqwest::Response) -> Response {
    let stream = futures_util::stream::unfold((response.bytes_stream(), Some((pool, port))), |(mut input, release)| async move {
        match input.next().await {
            Some(chunk) => Some((chunk.map_err(axum::Error::new), (input, release))),
            None => {
                if let Some((pool, port)) = release { pool.release(port).await; }
                None
            }
        }
    });
    (StatusCode::OK, [(header::CONTENT_TYPE, "text/event-stream"), (header::CACHE_CONTROL, "no-cache")], Body::from_stream(stream)).into_response()
}

async fn models() -> impl IntoResponse {
    Json(serde_json::json!({
        "object": "list",
        "data": [{"id": "ashat", "object": "model", "owned_by": "ashat"}]
    }))
}

#[derive(Serialize)]
struct HealthResponse {
    status: String,
    version: String,
}

async fn health() -> impl IntoResponse {
    Json(HealthResponse {
        status: "ok".to_string(),
        version: env!("CARGO_PKG_VERSION").to_string(),
    })
}

#[derive(Serialize)]
struct StatusResponse {
    queued_requests: usize,
    max_queue: usize,
    active_requests: usize,
    available_text_slots: usize,
    available_agent_slots: usize,
    available_vision_slots: usize,
    running_vision_instances: usize,
    max_vision_instances: usize,
}

async fn status(State(state): State<AppState>) -> impl IntoResponse {
    let queue = state.queue.read().await;
    let text_available = state.text_slots.available_permits();
    let agent_available = state.agent_slots.available_permits();
    let vision_available = state.vision_slots.available_permits();
    Json(StatusResponse {
        queued_requests: queue.queue_size(),
        max_queue: queue.max_queue_size(),
        active_requests: text_available.max(1) - text_available
            + agent_available.max(1) - agent_available
            + vision_available.max(1) - vision_available,
        available_text_slots: text_available,
        available_agent_slots: agent_available,
        available_vision_slots: vision_available,
        running_vision_instances: state.vision_pool.running_count().await,
        max_vision_instances: state.config.models.max_instances as usize,
    })
}

#[derive(Serialize)]
struct WorkersResponse {
    text_worker_healthy: bool,
    vision_worker_active: bool,
}

async fn workers(State(state): State<AppState>) -> impl IntoResponse {
    Json(WorkersResponse {
        text_worker_healthy: state.text_worker.is_alive().await,
        vision_worker_active: state.vision_pool.has_running().await,
    })
}
