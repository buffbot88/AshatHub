mod api;
mod queue;
mod router;
mod inference;

use anyhow::Result;
use std::sync::Arc;
use tokio::sync::RwLock;
use tower_http::cors::{Any, CorsLayer};
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};

use crate::queue::RequestQueue;
use crate::router::IntentRouter;

#[derive(Clone)]
pub struct AppState {
    pub queue: Arc<RwLock<RequestQueue>>,
    pub router: Arc<IntentRouter>,
}

#[tokio::main]
async fn main() -> Result<()> {
    // Initialize tracing
    tracing_subscriber::registry()
        .with(tracing_subscriber::EnvFilter::try_from_default_env()
            .unwrap_or_else(|_| "ashat_ai=info,tower_http=info".into()))
        .with(tracing_subscriber::fmt::layer())
        .init();

    // Load config
    let config = load_config().await?;
    tracing::info!("Loaded config - server port: {}", config.server.port);

    // Initialize intent router
    let intent_router = IntentRouter::new(config.clone());

    // Initialize request queue
    let queue = RequestQueue::new(config.queue.max_requests, config.queue.max_concurrent);

    // Create shared state
    let state = AppState {
        queue: Arc::new(RwLock::new(queue)),
        router: Arc::new(intent_router),
    };

    // Build CORS layer
    let cors = CorsLayer::new()
        .allow_origin(Any)
        .allow_methods(Any)
        .allow_headers(Any);

    // Build router
    let app = api::create_router(state).layer(cors);

    // Bind and serve
    let addr = format!("{}:{}", config.server.host, config.server.port);
    let listener = tokio::net::TcpListener::bind(&addr).await?;
    tracing::info!("Ashat AI service listening on {}", addr);

    axum::serve(listener, app).await?;

    Ok(())
}

#[derive(serde::Deserialize, Clone)]
pub struct Config {
    pub server: ServerConfig,
    pub models: ModelsConfig,
    pub omega: OmegaConfig,
    pub queue: QueueConfig,
}

#[derive(serde::Deserialize, Clone)]
pub struct ServerConfig {
    pub host: String,
    pub port: u16,
}

#[derive(serde::Deserialize, Clone)]
pub struct ModelsConfig {
    pub local_model: String,
    pub mmproj: String,
    pub max_instances: u32,
    pub cpu_cap: u32,
}

#[derive(serde::Deserialize, Clone)]
pub struct OmegaConfig {
    pub url: String,
    pub api_key: String,
    pub auth_header: String,
    pub model: String,
}

#[derive(serde::Deserialize, Clone)]
pub struct QueueConfig {
    pub max_requests: usize,
    pub max_concurrent: usize,
}

async fn load_config() -> Result<Config> {
    let content = tokio::fs::read_to_string("config.toml").await?;
    let config: Config = toml::from_str(&content)?;
    Ok(config)
}
