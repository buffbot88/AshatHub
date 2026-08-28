use alpha_common::{ChatMessage, Config};

#[derive(Debug, Clone, PartialEq)]
pub enum Intent {
    /// Text-only: route to always-on 350M text worker.
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

    /// Route a gateway-local request without allowing text to enter the VL path.
    pub fn classify_local(messages: &[ChatMessage]) -> Intent {
        if Self::has_images(messages) {
            Intent::Vision
        } else {
            Intent::LocalInference
        }
    }

    /// Classify the intent of an incoming request.
    pub fn classify(&self, messages: &[ChatMessage], _is_stream: bool) -> Intent {
        // Image detection takes priority — always route to vision pool.
        if Self::has_images(messages) {
            return Intent::Vision;
        }

        // Analyze message content for intent. Streaming is transport, not intent:
        // normal chat must remain on the always-on 350M worker.
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

        // Default to local text inference.
        Intent::LocalInference
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
    fn local_text_uses_text_worker() {
        assert_eq!(
            IntentRouter::classify_local(&[text_message()]),
            Intent::LocalInference
        );
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
