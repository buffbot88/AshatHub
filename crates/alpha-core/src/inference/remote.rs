use anyhow::Result;
use reqwest::Client;
use serde::{Deserialize, Serialize};

use alpha_common::{ChatMessage, ChatRequest, Config};

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
            .timeout(std::time::Duration::from_secs(120))
            .build()
            .expect("Failed to create HTTP client");

        Self { config, client }
    }

    /// Send a request to Omega server
    pub async fn infer(&self, request: &ChatRequest) -> Result<OmegaResponse> {
        let endpoint = format!("{}/v1/chat/completions", self.config.omega.url);
        
        let payload = OmegaRequest {
            model: self.config.omega.model.clone(),
            messages: request.messages.clone(),
            max_tokens: request.max_tokens.min(4096),
            temperature: request.temperature,
            stream: false,
        };

        let mut last_error = None;
        let max_retries = 3;
        let backoff = [1, 3, 5];

        for attempt in 0..max_retries {
            match self.client
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
                        return Ok(body);
                    }

                    // Check for transient errors
                    if status.as_u16() == 429 || status.as_u16() >= 500 {
                        tracing::warn!(
                            "Transient error from Omega: {}, attempt {}/{}",
                            status,
                            attempt + 1,
                            max_retries
                        );
                        tokio::time::sleep(std::time::Duration::from_secs(backoff[attempt])).await;
                        continue;
                    }

                    // Non-transient error
                    let body = response.text().await.unwrap_or_default();
                    return Err(anyhow::anyhow!("Omega error {}: {}", status, body));
                }
                Err(e) => {
                    tracing::warn!(
                        "Connection error to Omega: {}, attempt {}/{}",
                        e,
                        attempt + 1,
                        max_retries
                    );
                    last_error = Some(e);
                    tokio::time::sleep(std::time::Duration::from_secs(backoff[attempt])).await;
                }
            }
        }

        Err(anyhow::anyhow!(
            "Failed to connect to Omega after {} attempts: {:?}",
            max_retries,
            last_error
        ))
    }

    /// Get the base URL
    #[allow(dead_code)]
    pub fn base_url(&self) -> &str {
        &self.config.omega.url
    }

    /// Check if Omega is reachable
    #[allow(dead_code)]
    pub async fn health_check(&self) -> bool {
        let endpoint = format!("{}/health", self.config.omega.url);
        
        self.client
            .get(&endpoint)
            .header(&self.config.omega.auth_header, &self.config.omega.api_key)
            .send()
            .await
            .map(|r| r.status().is_success())
            .unwrap_or(false)
    }
}
