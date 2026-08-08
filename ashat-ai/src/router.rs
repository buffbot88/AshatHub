use crate::Config;

#[derive(Debug, Clone, PartialEq)]
pub enum Intent {
    LocalInference,
    ChatStudio,
    FileGeneration,
    Unknown,
}

pub struct IntentRouter {
    config: Config,
}

impl IntentRouter {
    pub fn new(config: Config) -> Self {
        Self { config }
    }

    pub fn config(&self) -> &Config {
        &self.config
    }

    /// Classify the intent of an incoming request
    pub fn classify(&self, messages: &[ChatMessage], is_stream: bool) -> Intent {
        // Check if this is a Chat Studio request
        // Chat Studio typically sends streaming requests
        if is_stream {
            return Intent::ChatStudio;
        }

        // Analyze message content for intent
        if let Some(last_msg) = messages.last() {
            let content = last_msg.content.to_lowercase();

            // File generation patterns
            if content.contains("generate") 
                || content.contains("create file")
                || content.contains("write code")
                || content.contains("build")
            {
                return Intent::FileGeneration;
            }

            // Chat patterns
            if content.contains("chat")
                || content.contains("ask")
                || content.contains("question")
                || content.contains("help")
            {
                return Intent::ChatStudio;
            }
        }

        // Default to local inference for general tasks
        Intent::LocalInference
    }

    /// Get the appropriate endpoint for the given intent
    pub fn get_endpoint(&self, intent: &Intent) -> String {
        match intent {
            Intent::ChatStudio => {
                format!("{}/v1/chat/completions", self.config.omega.url)
            }
            Intent::LocalInference | Intent::FileGeneration => {
                // Local inference handled internally
                "local".to_string()
            }
            Intent::Unknown => {
                // Fallback to local
                "local".to_string()
            }
        }
    }

    /// Get headers for remote requests
    pub fn get_headers(&self) -> Vec<(String, String)> {
        vec![
            ("Content-Type".to_string(), "application/json".to_string()),
            (self.config.omega.auth_header.clone(), self.config.omega.api_key.clone()),
        ]
    }
}

#[derive(Debug, Clone, serde::Deserialize, serde::Serialize)]
pub struct ChatMessage {
    pub role: String,
    pub content: String,
}

#[derive(Debug, Clone, serde::Deserialize)]
pub struct ChatRequest {
    pub messages: Vec<ChatMessage>,
    #[serde(default)]
    pub stream: bool,
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
