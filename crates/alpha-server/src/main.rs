mod api;

use alpha_common::Config;
use alpha_core::demand::VisionPool;
use alpha_core::queue::RequestQueue;
use alpha_core::router::IntentRouter;
use alpha_core::backend::LiquidBackend;
use anyhow::Result;
use axum::extract::DefaultBodyLimit;
use std::{collections::HashMap, sync::Arc};
use tokio::sync::{RwLock, Semaphore};
use tower_http::cors::{Any, CorsLayer};
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};

#[derive(Clone)]
pub struct AppState {
    pub queue: Arc<RwLock<RequestQueue>>,
    pub router: Arc<IntentRouter>,
    pub liquid: Arc<LiquidBackend>,
    pub vision_pool: Arc<VisionPool>,
    pub vision_slots: Arc<Semaphore>,
    pub agent_slots: Arc<Semaphore>,
    pub account_slots: Arc<tokio::sync::Mutex<HashMap<String, Arc<Semaphore>>>>,
    pub config: Config,
}

#[tokio::main]
async fn main() -> Result<()> {
    // Initialize tracing
    tracing_subscriber::registry()
        .with(
            tracing_subscriber::EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| "ashat_ai=info,tower_http=info".into()),
        )
        .with(tracing_subscriber::fmt::layer())
        .init();

    // Load config
    let config = load_config().await?;
    tracing::info!(
        "Loaded config - server port: {}, Liquid endpoint: {}, VL idle timeout: {}s",
        config.server.port,
        config.liquid.endpoint,
        config.vision.idle_timeout_secs
    );

    // Initialize intent router
    let intent_router = IntentRouter::new(config.clone());

    // Initialize request queue
    let queue = RequestQueue::new(config.queue.max_requests, config.queue.max_concurrent);

    let liquid = Arc::new(LiquidBackend::new(config.liquid.clone())?);

    // Initialize the on-demand vision pool (450M VL).
    // min_instances = 0: VL instances are only started when images arrive.
    let vision_pool = Arc::new(VisionPool::new(config.clone()));
    for _ in 0..config.pool.min_instances {
        let port = vision_pool.acquire().await?;
        vision_pool.release(port).await;
    }

    alpha_core::supervision::spawn(vision_pool.clone(), std::time::Duration::from_secs(10));

    // Create shared state
    let state = AppState {
        queue: Arc::new(RwLock::new(queue)),
        router: Arc::new(intent_router),
        liquid,
        vision_pool,
        vision_slots: Arc::new(Semaphore::new(config.models.max_instances.max(1) as usize)),
        agent_slots: Arc::new(Semaphore::new(config.agents.endpoints.len().max(1))),
        account_slots: Arc::new(tokio::sync::Mutex::new(HashMap::new())),
        config: config.clone(),
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
