//! Supervision: background health checks + idle shutdown for both pools.

use std::sync::Arc;
use std::time::Duration;

use crate::demand::VisionPool;
use crate::text_worker::TextWorker;

/// Spawn the background supervisor loop. Runs forever.
/// Manages both the text worker and vision pool.
pub fn spawn(text_worker: Arc<TextWorker>, vision_pool: Arc<VisionPool>, interval: Duration) {
    tokio::spawn(async move {
        loop {
            tokio::time::sleep(interval).await;

            // Ensure text worker is alive — restart if dead.
            if let Err(e) = text_worker.ensure_running().await {
                tracing::error!("text worker supervision failed: {}", e);
            }

            // Shutdown idle VL instances + respawn dead in-use ones.
            vision_pool.supervise().await;
        }
    });
}
