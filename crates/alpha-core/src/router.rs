use alpha_common::{ChatMessage, Config};

#[derive(Debug, Clone, PartialEq)]
pub enum Intent {
    Liquid,
    Vision,
}

pub struct IntentRouter {
    config: Config,
}

impl IntentRouter {
    pub fn new(config: Config) -> Self { Self { config } }
    pub fn config(&self) -> &Config { &self.config }

    pub fn has_images(messages: &[ChatMessage]) -> bool {
        messages.iter().any(ChatMessage::has_image)
    }

    /// Routing is based only on request capabilities. Text is Liquid; images use VL.
    pub fn classify(&self, messages: &[ChatMessage], _is_stream: bool, _operation: Option<&str>) -> Intent {
        if Self::has_images(messages) { Intent::Vision } else { Intent::Liquid }
    }

    pub fn get_endpoint(&self, intent: &Intent) -> String {
        match intent {
            Intent::Liquid => format!("{}/v1/chat/completions", self.config.liquid.endpoint.trim_end_matches('/')),
            Intent::Vision => "local".into(),
        }
    }

    pub fn get_headers(&self) -> Vec<(String, String)> {
        vec![("Content-Type".into(), "application/json".into())]
    }
}
