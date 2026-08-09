//! Shared config & request types for Ashat Delta.

#[derive(serde::Deserialize, Clone)]
pub struct Config {
    pub server: ServerConfig,
    pub models: ModelsConfig,
    pub omega: OmegaConfig,
    pub queue: QueueConfig,
    pub pool: PoolConfig,
}

#[derive(serde::Deserialize, Clone)]
pub struct ServerConfig {
    pub host: String,
    pub port: u16,
}

#[derive(serde::Deserialize, Clone)]
pub struct ModelsConfig {
    pub local_model: String,
    pub mmproj: String,
    pub max_instances: u32,
    pub cpu_cap: u32,
}

#[derive(serde::Deserialize, Clone)]
pub struct OmegaConfig {
    pub url: String,
    pub api_key: String,
    pub auth_header: String,
    pub model: String,
}

#[derive(serde::Deserialize, Clone)]
pub struct QueueConfig {
    pub max_requests: usize,
    pub max_concurrent: usize,
}

#[derive(serde::Deserialize, Clone)]
pub struct PoolConfig {
    pub min_instances: u32,
    pub port_base: u16,
}

#[derive(Debug, Clone, serde::Deserialize, serde::Serialize)]
pub struct ChatMessage {
    pub role: String,
    pub content: MessageContent,
}

/// Message content: plain text, or OpenAI-style multimodal parts
/// (text + image_url) so the VL model can see images.
#[derive(Debug, Clone, serde::Deserialize, serde::Serialize)]
#[serde(untagged)]
pub enum MessageContent {
    Text(String),
    Parts(Vec<ContentPart>),
}

#[derive(Debug, Clone, serde::Deserialize, serde::Serialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ContentPart {
    Text { text: String },
    ImageUrl { image_url: ImageUrl },
}

#[derive(Debug, Clone, serde::Deserialize, serde::Serialize)]
pub struct ImageUrl {
    pub url: String,
}

impl ChatMessage {
    /// Joined plain text of a message (image parts contribute nothing).
    pub fn text(&self) -> String {
        match &self.content {
            MessageContent::Text(t) => t.clone(),
            MessageContent::Parts(parts) => parts
                .iter()
                .filter_map(|p| match p {
                    ContentPart::Text { text } => Some(text.clone()),
                    _ => None,
                })
                .collect::<Vec<_>>()
                .join(" "),
        }
    }
}

#[derive(Debug, Clone, serde::Deserialize)]
pub struct ChatRequest {
    pub messages: Vec<ChatMessage>,
    #[serde(default)]
    pub stream: bool,
    #[serde(default)]
    pub model: Option<String>,
    #[serde(default = "default_max_tokens")]
    pub max_tokens: u32,
    #[serde(default = "default_temperature")]
    pub temperature: f32,
}

fn default_max_tokens() -> u32 {
    1024
}

fn default_temperature() -> f32 {
    0.7
}
