use alpha_common::{ChatMessage, Config};

#[derive(Debug, Clone, PartialEq)]
pub enum Intent {
    /// Text-only helper/router work on the local 350M worker.
    LocalInference,
    /// Has images: route to on-demand 450M VL vision pool.
    Vision,
    /// Route to Omega/Beta/Delta coding agents.
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

    /// Check if any message in the request contains images.
    pub fn has_images(messages: &[ChatMessage]) -> bool {
        messages.iter().any(|m| m.has_image())
    }

    /// Route local chat and vision through the 450M VL worker.
    pub fn classify_local(_messages: &[ChatMessage]) -> Intent {
        Intent::Vision
    }

    /// Classify the intent of an incoming request.
    pub fn classify(&self, messages: &[ChatMessage], _is_stream: bool, mode: Option<&str>) -> Intent {
        // Image detection takes priority — always route to vision pool.
        if Self::has_images(messages) {
            return Intent::Vision;
        }

        match mode {
            Some("chat") | Some("plan") => return Intent::Vision,
            Some("vision") => return Intent::Vision,
            Some("build") => return Intent::FileGeneration,
            Some("debug") => return Intent::ChatStudio,
            _ => {}
        }

        // Analyze message content for intent. Streaming is transport, not intent.
        if let Some(last_msg) = messages.last() {
            let content = last_msg.text().to_lowercase();

            if content.contains("generate")
                || content.contains("create file")
                || content.contains("write code")
                || content.contains("complete code")
                || content.contains("implementation")
                || content.contains("implement")
                || content.contains("build")
            {
                return Intent::FileGeneration;
            }

        }

        // Default chat uses the 450M VL worker.
        Intent::Vision
    }

    /// Get the appropriate endpoint for the given intent.
    pub fn get_endpoint(&self, intent: &Intent) -> String {
        match intent {
            Intent::ChatStudio => {
                let base = self
                    .config
                    .agents
                    .endpoints
                    .first()
                    .map(|endpoint| endpoint.url.as_str())
                    .unwrap_or(self.config.omega.url.as_str());
                format!("{}/v1/chat/completions", base.trim_end_matches('/'))
            }
            Intent::LocalInference | Intent::FileGeneration | Intent::Vision => "local".to_string(),
            Intent::Unknown => "local".to_string(),
        }
    }

    /// Get headers for remote requests.
    pub fn get_headers(&self) -> Vec<(String, String)> {
        vec![
            ("Content-Type".to_string(), "application/json".to_string()),
            (
                self.config.omega.auth_header.clone(),
                self.config.omega.api_key.clone(),
            ),
        ]
    }
}

#[cfg(test)]
mod tests {
    use super::{Intent, IntentRouter};
    use alpha_common::{ChatMessage, ContentPart, ImageUrl, MessageContent};

    fn text_message() -> ChatMessage {
        ChatMessage {
            role: "user".into(),
            content: MessageContent::Text("hello".into()),
        }
    }

    #[test]
    fn local_text_uses_vl_worker() {
        assert_eq!(
            IntentRouter::classify_local(&[text_message()]),
            Intent::Vision
        );
    }

    #[test]
    fn explicit_modes_use_expected_workers() {
        let router = IntentRouter::new(alpha_common::Config {
            server: alpha_common::ServerConfig { host: "127.0.0.1".into(), port: 3000 },
            models: alpha_common::ModelsConfig { local_model: "local".into(), mmproj: "mmproj".into(), max_instances: 3, cpu_cap: 90 },
            omega: alpha_common::OmegaConfig { url: "http://omega".into(), api_key: "key".into(), auth_header: "X-Key".into(), model: "model".into() },
            agents: Default::default(),
            queue: alpha_common::QueueConfig { max_requests: 1, max_concurrent: 1 },
            pool: alpha_common::PoolConfig { min_instances: 0, port_base: 3001 },
            text_worker: Default::default(),
            vision: Default::default(),
        });
        assert_eq!(router.classify(&[text_message()], true, Some("chat")), Intent::Vision);
        assert_eq!(router.classify(&[text_message()], true, Some("plan")), Intent::Vision);
        assert_eq!(router.classify(&[text_message()], true, Some("vision")), Intent::Vision);
        assert_eq!(router.classify(&[text_message()], true, Some("build")), Intent::FileGeneration);
        assert_eq!(router.classify(&[text_message()], true, Some("debug")), Intent::ChatStudio);
    }

    #[test]
    fn local_image_uses_vision_worker() {
        let message = ChatMessage {
            role: "user".into(),
            content: MessageContent::Parts(vec![ContentPart::ImageUrl {
                image_url: ImageUrl {
                    url: "data:image/png;base64,abc".into(),
                },
            }]),
        };
        assert_eq!(IntentRouter::classify_local(&[message]), Intent::Vision);
    }
}
