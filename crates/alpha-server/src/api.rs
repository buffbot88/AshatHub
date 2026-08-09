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
use alpha_core::router::Intent;

pub fn create_router(state: AppState) -> Router {
    Router::new()
        .route("/v1/chat/completions", post(chat_completions))
        .route("/health", get(health))
        .route("/status", get(status))
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
        if let Err(_) = queue.enqueue(request_id) {
            return (
                StatusCode::TOO_MANY_REQUESTS,
                Json(serde_json::json!({
                    "error": "Queue full",
                    "message": "Maximum requests in queue reached"
                })),
            );
        }
    }
    // Wait for a free slot — this is the "4th request waits" behavior.
    let _permit = match state.queue.read().await.acquire_slot().await {
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

    // Classify intent — "model": "local" (Chat Mode) always routes to
    // the pooled 450M VL, bypassing keyword heuristics.
    let intent = if request.model.as_deref() == Some("local") {
        Intent::LocalInference
    } else {
        state.router.classify(&request.messages, request.stream)
    };
    tracing::info!("Classified intent: {:?}", intent);

    match intent {
        Intent::ChatStudio => {
            // Route to Omega server
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
        Intent::LocalInference | Intent::FileGeneration => {
            // Route to a pooled llama-server instance (kills the old placeholder)
            match alpha_core::proxy::chat_completions(&state.pool, &request).await {
                Ok(body) => (StatusCode::OK, Json(body)),
                Err(e) => {
                    tracing::error!("Local inference failed: {}", e);
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
        Intent::Unknown => {
            (
                StatusCode::BAD_REQUEST,
                Json(serde_json::json!({
                    "error": "Unknown intent",
                    "message": "Could not classify the request"
                })),
            )
        }
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
