use anyhow::Result;
use reqwest::Client;
use serde::{Deserialize, Serialize};
use std::sync::atomic::{AtomicUsize, Ordering};

use alpha_common::{AgentEndpoint, ChatMessage, ChatRequest, Config};

static NEXT_ENDPOINT: AtomicUsize = AtomicUsize::new(0);

pub struct RemoteInference {
    config: Config,
    client: Client,
}

#[derive(Debug, Serialize)]
struct OmegaRequest {
    model: String,
    messages: Vec<ChatMessage>,
    max_tokens: u32,
    temperature: f32,
    stream: bool,
}

#[derive(Debug, Deserialize, Serialize)]
pub struct OmegaResponse {
    pub choices: Vec<Choice>,
    pub usage: Option<Usage>,
}

#[derive(Debug, Deserialize, Serialize)]
pub struct Choice {
    pub message: Option<ChatMessage>,
    pub delta: Option<Delta>,
    pub finish_reason: Option<String>,
}

#[derive(Debug, Deserialize, Serialize)]
pub struct Delta {
    pub content: Option<String>,
}

#[derive(Debug, Deserialize, Serialize)]
pub struct Usage {
    pub prompt_tokens: u32,
    pub completion_tokens: u32,
    pub total_tokens: u32,
}

impl RemoteInference {
    pub fn new(config: Config) -> Self {
        let client = Client::builder()
            .timeout(std::time::Duration::from_secs(180))
            .build()
            .expect("Failed to create HTTP client");

        Self { config, client }
    }

    fn endpoints(&self) -> Vec<AgentEndpoint> {
        if !self.config.agents.endpoints.is_empty() {
            return self.config.agents.endpoints.clone();
        }

        // Backward-compatible fallback for older config files.
        vec![AgentEndpoint {
            id: "omega".to_string(),
            url: self.config.omega.url.clone(),
        }]
    }

    /// Send a request across the configured Omega/Beta/Delta pool.
    pub async fn infer(&self, request: &ChatRequest) -> Result<OmegaResponse> {
        let payload = OmegaRequest {
            model: self.config.omega.model.clone(),
            messages: request.messages.clone(),
            max_tokens: request.max_tokens.min(4096),
            temperature: request.temperature,
            stream: false,
        };

        let backoff = [1, 3, 5];
        let mut errors = Vec::new();
        let endpoints = self.endpoints();
        if endpoints.is_empty() {
            return Err(anyhow::anyhow!("No coding agent endpoints configured"));
        }
        let start = NEXT_ENDPOINT.fetch_add(1, Ordering::Relaxed) % endpoints.len();

        for offset in 0..endpoints.len() {
            let endpoint_config = &endpoints[(start + offset) % endpoints.len()];
            let endpoint = format!(
                "{}/v1/chat/completions",
                endpoint_config.url.trim_end_matches('/')
            );

            for (attempt, delay) in backoff.iter().enumerate() {
                match self
                    .client
                    .post(&endpoint)
                    .header(&self.config.omega.auth_header, &self.config.omega.api_key)
                    .header("Content-Type", "application/json")
                    .json(&payload)
                    .send()
                    .await
                {
                    Ok(response) => {
                        let status = response.status();

                        if status.is_success() {
                            let body: OmegaResponse = response.json().await?;
                            tracing::info!("Coding request served by {}", endpoint_config.id);
                            return Ok(body);
                        }

                        let body = response.text().await.unwrap_or_default();
                        if status.as_u16() == 429 || status.as_u16() >= 500 {
                            tracing::warn!(
                                "Transient error from {}: {}, attempt {}/{}",
                                endpoint_config.id,
                                status,
                                attempt + 1,
                                backoff.len()
                            );
                            errors.push(format!("{} returned {}", endpoint_config.id, status));
                            if attempt + 1 < backoff.len() {
                                tokio::time::sleep(std::time::Duration::from_secs(*delay)).await;
                            }
                            continue;
                        }

                        errors.push(format!(
                            "{} returned {}: {}",
                            endpoint_config.id, status, body
                        ));
                        break;
                    }
                    Err(error) => {
                        tracing::warn!(
                            "Connection error to {}: {}, attempt {}/{}",
                            endpoint_config.id,
                            error,
                            attempt + 1,
                            backoff.len()
                        );
                        errors.push(format!("{} connection failed", endpoint_config.id));
                        if attempt + 1 < backoff.len() {
                            tokio::time::sleep(std::time::Duration::from_secs(*delay)).await;
                        }
                    }
                }
            }
        }

        Err(anyhow::anyhow!(
            "All coding agent endpoints failed: {}",
            errors.join("; ")
        ))
    }

    /// Get the legacy primary URL for diagnostics.
    #[allow(dead_code)]
    pub fn base_url(&self) -> &str {
        &self.config.omega.url
    }

    /// Check whether at least one configured agent endpoint is reachable.
    #[allow(dead_code)]
    pub async fn health_check(&self) -> bool {
        for endpoint_config in self.endpoints() {
            let endpoint = format!("{}/health", endpoint_config.url.trim_end_matches('/'));
            let reachable = self
                .client
                .get(&endpoint)
                .header(&self.config.omega.auth_header, &self.config.omega.api_key)
                .send()
                .await
                .map(|response| response.status().is_success())
                .unwrap_or(false);
            if reachable {
                return true;
            }
        }
        false
    }
}
