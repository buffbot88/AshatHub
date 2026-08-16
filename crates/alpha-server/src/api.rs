use axum::{
    extract::State,
    http::StatusCode,
    response::IntoResponse,
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
        .route("/health", get(health))
        .route("/status", get(status))
        .route("/workers", get(workers))
        .with_state(state)
}

async fn chat_completions(
    State(state): State<AppState>,
    Json(request): Json<ChatRequest>,
) -> impl IntoResponse {
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
            );
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
            );
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
                Ok(body) => (StatusCode::OK, Json(body)),
                Err(e) => {
                    tracing::error!("Vision inference failed: {}", e);
                    (
                        StatusCode::BAD_GATEWAY,
                        Json(serde_json::json!({
                            "error": "Vision inference failed",
                            "message": e.to_string()
                        })),
                    )
                }
            }
        }

        // ── Text: always-on 350M text worker ───────────────────
        Intent::LocalInference => {
            match alpha_core::proxy::text_worker_completions(&state.text_worker, &request).await {
                Ok(body) => (StatusCode::OK, Json(body)),
                Err(e) => {
                    tracing::error!("Text worker inference failed: {}", e);
                    (
                        StatusCode::BAD_GATEWAY,
                        Json(serde_json::json!({
                            "error": "Text worker inference failed",
                            "message": e.to_string()
                        })),
                    )
                }
            }
        }

        // ── Coding: Omega/Beta/Delta ───────────────────────────
        Intent::ChatStudio | Intent::FileGeneration => {
            let remote = alpha_core::inference::RemoteInference::new(state.router.config().clone());
            match remote.infer(&request).await {
                Ok(response) => (StatusCode::OK, Json(serde_json::json!(response))),
                Err(e) => {
                    tracing::error!("Omega inference failed: {}", e);
                    (
                        StatusCode::BAD_GATEWAY,
                        Json(serde_json::json!({
                            "error": "Inference failed",
                            "message": e.to_string()
                        })),
                    )
                }
            }
        }

        Intent::Unknown => (
            StatusCode::BAD_REQUEST,
            Json(serde_json::json!({
                "error": "Unknown intent",
                "message": "Could not classify the request"
            })),
        ),
    }
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
