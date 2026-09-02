use std::{future::Future, pin::Pin, time::Duration};

use alpha_common::{ChatRequest, LiquidConfig};

pub type ChatStream = reqwest::Response;
pub type ChatResult<'a> = Pin<Box<dyn Future<Output = anyhow::Result<ChatStream>> + Send + 'a>>;

pub trait ChatBackend: Send + Sync {
    fn stream<'a>(&'a self, request: &'a ChatRequest) -> ChatResult<'a>;
}

pub struct LiquidBackend {
    client: reqwest::Client,
    config: LiquidConfig,
}

impl LiquidBackend {
    pub fn new(config: LiquidConfig) -> anyhow::Result<Self> {
        Ok(Self {
            client: reqwest::Client::builder().timeout(Duration::from_secs(120)).build()?,
            config,
        })
    }

    async fn request(&self, request: &ChatRequest) -> anyhow::Result<ChatStream> {
        let response = self.client
            .post(format!("{}/v1/chat/completions", self.config.endpoint.trim_end_matches('/')))
            .json(&serde_json::json!({
                "model": self.config.model,
                "messages": request.messages,
                "max_tokens": request.max_tokens,
                "temperature": request.temperature,
                "stream": true,
                "tools": request.tools,
                "tool_choice": "auto",
            }))
            .send()
            .await?;
        if !response.status().is_success() {
            return Err(anyhow::anyhow!("Liquid backend returned {}", response.status()));
        }
        Ok(response)
    }
}

impl ChatBackend for LiquidBackend {
    fn stream<'a>(&'a self, request: &'a ChatRequest) -> ChatResult<'a> {
        Box::pin(self.request(request))
    }
}
