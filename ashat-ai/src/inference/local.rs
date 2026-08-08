use anyhow::Result;
use std::sync::Arc;
use tokio::sync::Semaphore;

use crate::Config;

pub struct LocalInference {
    #[allow(dead_code)]
    config: Config,
    semaphore: Arc<Semaphore>,
}

impl LocalInference {
    pub fn new(config: Config) -> Self {
        let semaphore = Arc::new(Semaphore::new(config.models.max_instances as usize));
        Self { config, semaphore }
    }

    /// Check if a local instance is available
    #[allow(dead_code)]
    pub async fn is_available(&self) -> bool {
        let available_permits = self.semaphore.available_permits();
        tracing::debug!("Local instances available: {}", available_permits);
        available_permits > 0
    }

    /// Acquire a local instance for inference
    pub async fn acquire(&self) -> Result<tokio::sync::OwnedSemaphorePermit> {
        let permit = self.semaphore
            .clone()
            .acquire_owned()
            .await
            .map_err(|e| anyhow::anyhow!("Failed to acquire instance: {}", e))?;
        
        tracing::info!("Acquired local VL instance");
        Ok(permit)
    }

    /// Run inference locally
    pub async fn infer(&self, prompt: &str, _max_tokens: u32) -> Result<String> {
        let _permit = self.acquire().await?;
        
        tracing::info!("Running local inference for prompt (len: {})", prompt.len());
        
        // TODO: Implement actual llama.cpp inference
        // For now, return a placeholder
        Ok(format!("Local inference result for: {}", prompt))
    }

    /// Get the number of active instances
    #[allow(dead_code)]
    pub fn active_instances(&self) -> usize {
        self.config.models.max_instances as usize - self.semaphore.available_permits()
    }

    /// Get the model path
    #[allow(dead_code)]
    pub fn model_path(&self) -> &str {
        &self.config.models.local_model
    }

    /// Get the mmproj path
    #[allow(dead_code)]
    pub fn mmproj_path(&self) -> &str {
        &self.config.models.mmproj
    }
}
