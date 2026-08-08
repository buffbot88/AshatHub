use axum::{
    extract::State,
    http::StatusCode,
    response::IntoResponse,
    routing::{get, post},
    Json, Router,
};
use serde::Serialize;

use crate::AppState;
use crate::router::{ChatRequest, Intent};

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
    // Check if queue is full
    {
        let queue = state.queue.read().await;
        if queue.is_full() {
            return (
                StatusCode::TOO_MANY_REQUESTS,
                Json(serde_json::json!({
                    "error": "Queue full",
                    "message": "Maximum requests in queue reached"
                })),
            );
        }
    }

    // Classify intent
    let intent = state.router.classify(&request.messages, request.stream);
    tracing::info!("Classified intent: {:?}", intent);

    match intent {
        Intent::ChatStudio => {
            // Route to Omega server
            let remote = crate::inference::RemoteInference::new(state.router.config().clone());

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
            // Route to local VL instance
            let local = crate::inference::LocalInference::new(state.router.config().clone());

            let prompt = request.messages.last()
                .map(|m| m.content.as_str())
                .unwrap_or("");

            match local.infer(prompt, request.max_tokens).await {
                Ok(response) => {
                    (StatusCode::OK, Json(serde_json::json!({
                        "choices": [{
                            "message": {
                                "role": "assistant",
                                "content": response
                            },
                            "finish_reason": "stop"
                        }]
                    })))
                }
                Err(e) => {
                    tracing::error!("Local inference failed: {}", e);
                    (
                        StatusCode::INTERNAL_SERVER_ERROR,
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
