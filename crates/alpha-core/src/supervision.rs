//! Supervision: periodic health check + respawn of pooled llama-server instances.

use std::sync::Arc;
use std::time::Duration;

use crate::demand::InstancePool;

/// Spawn the background supervisor loop. Runs forever.
pub fn spawn(pool: Arc<InstancePool>, interval: Duration) {
    tokio::spawn(async move {
        loop {
            tokio::time::sleep(interval).await;
            pool.supervise().await;
        }
    });
}
