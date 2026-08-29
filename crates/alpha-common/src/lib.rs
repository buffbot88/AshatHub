//! Shared config & request types for Ashat AI.

#[derive(serde::Deserialize, Clone)]
pub struct Config {
    pub server: ServerConfig,
    pub models: ModelsConfig,
    pub omega: OmegaConfig,
    /// IP-based Omega/Beta/Delta failover endpoints.
    #[serde(default)]
    pub agents: AgentPoolConfig,
    pub queue: QueueConfig,
    pub pool: PoolConfig,
    #[serde(default)]
    pub text_worker: TextWorkerConfig,
    #[serde(default)]
    pub vision: VisionConfig,
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

/// Always-on text worker (350M). Handles intent classification,
/// build planning, conversation, brainstorming.
#[derive(serde::Deserialize, Clone)]
pub struct TextWorkerConfig {
    pub model: String,
    pub port: u16,
    #[serde(default = "default_ctx_size")]
    pub ctx_size: u32,
    #[serde(default = "default_true")]
    pub always_on: bool,
}

impl Default for TextWorkerConfig {
    fn default() -> Self {
        Self {
            model: "../../models/LFM2.5-350M-Q4_K_M.gguf".into(),
            port: 3005,
            ctx_size: 4096,
            always_on: true,
        }
    }
}

fn default_ctx_size() -> u32 {
    4096
}
fn default_true() -> bool {
    true
}

/// On-demand vision pool (450M VL). Only started when an image
/// needs to be read. Auto-stops after idle timeout.
#[derive(serde::Deserialize, Clone)]
pub struct VisionConfig {
    pub idle_timeout_secs: u64,
    #[serde(default = "default_true")]
    pub on_demand: bool,
}

impl Default for VisionConfig {
    fn default() -> Self {
        Self {
            idle_timeout_secs: 300,
            on_demand: true,
        }
    }
}

#[derive(serde::Deserialize, Clone)]
pub struct OmegaConfig {
    pub url: String,
    pub api_key: String,
    pub auth_header: String,
    pub model: String,
}

#[derive(serde::Deserialize, Clone, Default)]
pub struct AgentPoolConfig {
    #[serde(default)]
    pub endpoints: Vec<AgentEndpoint>,
}

#[derive(serde::Deserialize, Clone)]
pub struct AgentEndpoint {
    pub id: String,
    pub url: String,
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

    /// Returns true if this message contains image parts.
    pub fn has_image(&self) -> bool {
        match &self.content {
            MessageContent::Text(_) => false,
            MessageContent::Parts(parts) => parts
                .iter()
                .any(|p| matches!(p, ContentPart::ImageUrl { .. })),
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
    #[serde(default)]
    pub mode: Option<String>,
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
