//! Background supervision for the on-demand vision pool.

use std::sync::Arc;
use std::time::Duration;

use crate::demand::VisionPool;

pub fn spawn(vision_pool: Arc<VisionPool>, interval: Duration) {
    tokio::spawn(async move {
        loop {
            tokio::time::sleep(interval).await;
            vision_pool.supervise().await;
        }
    });
}
