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

#[derive(Debug, Deserialize)]
struct HealthResponse {
    coding_agent_capacity: Option<Capacity>,
}

#[derive(Debug, Deserialize)]
struct Capacity {
    ports_active: usize,
    ports_total: usize,
    queue_depth: usize,
    queue_limit: usize,
    memory_pressure: Option<f64>,
    worker_startup_latency_ms: Option<f64>,
    recent_failure_rate: Option<f64>,
    estimated_request_cost: Option<f64>,
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
        let order = self.endpoint_order(&endpoints, start).await;

        for index in order {
            let endpoint_config = &endpoints[index];
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

    async fn endpoint_order(&self, endpoints: &[AgentEndpoint], start: usize) -> Vec<usize> {
        let mut tasks = tokio::task::JoinSet::new();
        for (index, endpoint) in endpoints.iter().enumerate() {
            let client = self.client.clone();
            let url = format!("{}/health", endpoint.url.trim_end_matches('/'));
            let auth_header = self.config.omega.auth_header.clone();
            let api_key = self.config.omega.api_key.clone();
            tasks.spawn(async move {
                let capacity = match client.get(url).header(auth_header, api_key).send().await {
                    Ok(response) if response.status().is_success() => response
                        .json::<HealthResponse>()
                        .await
                        .ok()
                        .and_then(|health| health.coding_agent_capacity)
                        .map(|capacity| {
                            let total = (capacity.ports_total + capacity.queue_limit).max(1) as f64;
                            let queue_load = (capacity.ports_active + capacity.queue_depth) as f64 / total;
                            let memory = capacity.memory_pressure.unwrap_or(0.5).clamp(0.0, 1.0);
                            let failures = capacity.recent_failure_rate.unwrap_or(0.0).clamp(0.0, 1.0);
                            let startup = (capacity.worker_startup_latency_ms.unwrap_or(0.0) / 30_000.0).clamp(0.0, 1.0);
                            let cost = (capacity.estimated_request_cost.unwrap_or(0.0) / 30_000.0).clamp(0.0, 1.0);
                            queue_load + memory * 0.25 + failures * 0.5 + startup * 0.1 + cost * 0.1
                        }),
                    _ => None,
                };
                (index, capacity)
            });
        }
        let mut ranked = Vec::new();
        while let Some(result) = tasks.join_next().await {
            if let Ok((index, Some(load))) = result { ranked.push((load, (index + endpoints.len() - start) % endpoints.len(), index)); }
        }
        if ranked.is_empty() {
            return (0..endpoints.len()).map(|offset| (start + offset) % endpoints.len()).collect();
        }
        ranked.sort_by(|a, b| a.partial_cmp(b).unwrap_or(std::cmp::Ordering::Equal));
        ranked.into_iter().map(|(_, _, index)| index).collect()
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
