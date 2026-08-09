mod api;

use anyhow::Result;
use alpha_common::Config;
use alpha_core::demand::InstancePool;
use alpha_core::queue::RequestQueue;
use alpha_core::router::IntentRouter;
use axum::extract::DefaultBodyLimit;
use std::sync::Arc;
use tokio::sync::RwLock;
use tower_http::cors::{Any, CorsLayer};
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};

#[derive(Clone)]
pub struct AppState {
    pub queue: Arc<RwLock<RequestQueue>>,
    pub router: Arc<IntentRouter>,
    pub pool: Arc<InstancePool>,
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

    // Demand pool: spawn min instances, supervise + respawn in the background.
    // Non-fatal: if llama-server isn't up yet, requests spawn instances on demand.
    let pool = Arc::new(InstancePool::new(config.clone()));
    if let Err(e) = pool.ensure_min().await {
        tracing::warn!("initial pool spawn failed: {} (supervision will retry)", e);
    }
    alpha_core::supervision::spawn(pool.clone(), std::time::Duration::from_secs(10));

    // Create shared state
    let state = AppState {
        queue: Arc::new(RwLock::new(queue)),
        router: Arc::new(intent_router),
        pool,
    };

    // Build CORS layer
    let cors = CorsLayer::new()
        .allow_origin(Any)
        .allow_methods(Any)
        .allow_headers(Any);

    // Build router. Image chat bodies are base64 data-URLs (multi-MB for
    // real photos) — axum's 2MB default would abort the proxy mid-body.
    let app = api::create_router(state)
        .layer(cors)
        .layer(DefaultBodyLimit::max(32 * 1024 * 1024));

    // Bind and serve
    let addr = format!("{}:{}", config.server.host, config.server.port);
    let listener = tokio::net::TcpListener::bind(&addr).await?;
    tracing::info!("Ashat AI service listening on {}", addr);

    axum::serve(listener, app).await?;

    Ok(())
}

async fn load_config() -> Result<Config> {
    let content = tokio::fs::read_to_string("config.toml").await?;
    let config: Config = toml::from_str(&content)?;
    Ok(config)
}
