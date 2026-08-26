use axum::{
    extract::State,
    http::{header, StatusCode},
    response::{IntoResponse, Response},
    routing::{get, post},
    Json, Router,
};
use serde::Serialize;

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
    Json(request): Json<ChatRequest>,
) -> Response {
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
    // Clone the semaphore before awaiting. Holding the queue's read lock
    // while waiting would starve the writer that dequeues completed work.
    let semaphore = { state.queue.read().await.semaphore() };
    let _permit = match semaphore.acquire_owned().await {
        Ok(p) => p,
        Err(e) => {
            tracing::error!("acquire slot failed: {}", e);
            return (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(serde_json::json!({ "error": "Queue error" })),
            ).into_response();
        }
    };
    {
        let mut queue = state.queue.write().await;
        queue.dequeue();
    }

    // Classify intent — image detection + "model": "local" bypass.
    let intent = if request.model.as_deref() == Some("local") {
        // "model": "local" from AshatHub — check for images.
        IntentRouter::classify_local(&request.messages)
    } else {
        state.router.classify(&request.messages, request.stream)
    };

    tracing::info!(
        "Classified intent: {:?} ({} messages)",
        intent,
        request.messages.len()
    );

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
    queue_size: usize,
    max_queue: usize,
    available_slots: usize,
}

async fn status(State(state): State<AppState>) -> impl IntoResponse {
    let queue = state.queue.read().await;
    Json(StatusResponse {
        queue_size: queue.queue_size(),
        max_queue: queue.max_queue_size(),
        available_slots: queue.available_slots(),
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
