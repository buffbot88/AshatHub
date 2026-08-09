use std::collections::VecDeque;
use std::sync::Arc;
use tokio::sync::{Semaphore, OwnedSemaphorePermit};
use anyhow::Result;

pub struct RequestQueue {
    queue: VecDeque<QueuedRequest>,
    max_requests: usize,
    semaphore: Arc<Semaphore>,
}

struct QueuedRequest {
    id: String,
    // Could add more metadata here
}

impl RequestQueue {
    pub fn new(max_requests: usize, max_concurrent: usize) -> Self {
        Self {
            queue: VecDeque::with_capacity(max_requests),
            max_requests,
            semaphore: Arc::new(Semaphore::new(max_concurrent)),
        }
    }

    /// Try to enqueue a request
    pub fn enqueue(&mut self, request_id: String) -> Result<()> {
        if self.queue.len() >= self.max_requests {
            return Err(anyhow::anyhow!(
                "Queue full: {}/{} requests",
                self.queue.len(),
                self.max_requests
            ));
        }

        self.queue.push_back(QueuedRequest { id: request_id });
        tracing::debug!("Enqueued request: {}, queue size: {}", self.queue.front().map(|r| r.id.as_str()).unwrap_or("unknown"), self.queue.len());
        
        Ok(())
    }

    /// Try to acquire a slot for processing
    pub async fn acquire_slot(&self) -> Result<OwnedSemaphorePermit> {
        Arc::clone(&self.semaphore)
            .acquire_owned()
            .await
            .map_err(|e| anyhow::anyhow!("Failed to acquire slot: {}", e))
    }

    /// Get current queue size
    pub fn queue_size(&self) -> usize {
        self.queue.len()
    }

    /// Get max queue size
    pub fn max_queue_size(&self) -> usize {
        self.max_requests
    }

    /// Check if queue is full
    pub fn is_full(&self) -> bool {
        self.queue.len() >= self.max_requests
    }

    /// Pop the oldest queued request.
    pub fn dequeue(&mut self) {
        self.queue.pop_front();
    }

    /// Get available slots
    pub fn available_slots(&self) -> usize {
        self.semaphore.available_permits()
    }
}
