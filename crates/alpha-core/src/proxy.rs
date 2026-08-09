//! Proxy: route chat requests to a free pooled llama-server instance.

use std::time::Duration;

use alpha_common::ChatRequest;

use crate::demand::InstancePool;

/// Send a chat completion to a pooled instance (acquires, retries cold start, releases).
/// The instance's raw OpenAI-shaped JSON is passed through unchanged.
pub async fn chat_completions(
    pool: &InstancePool,
    request: &ChatRequest,
) -> anyhow::Result<serde_json::Value> {
    let port = pool.acquire().await?;
    let url = format!("http://127.0.0.1:{}/v1/chat/completions", port);
    let payload = serde_json::json!({
        "model": "local",
        "messages": request.messages,
        "max_tokens": request.max_tokens.min(4096),
        "temperature": request.temperature,
        "stream": false,
    });
    let client = reqwest::Client::builder()
        .timeout(Duration::from_secs(120))
        .build()?;

    // ponytail: bounded 40s retry covers cold model load and supervision respawn
    let mut last_err: Option<anyhow::Error> = None;
    for _attempt in 0..40 {
        match client.post(&url).json(&payload).send().await {
            Ok(resp) => {
                let status = resp.status();
                // Cold start: llama-server answers 503 while loading the model — retry.
                if status == reqwest::StatusCode::SERVICE_UNAVAILABLE {
                    tokio::time::sleep(Duration::from_secs(2)).await;
                    continue;
                }
                let body: serde_json::Value = resp.json().await?;
                pool.release(port).await;
                if status.is_success() {
                    return Ok(body);
                }
                return Err(anyhow::anyhow!(
                    "llama-server :{} returned {}: {}",
                    port,
                    status,
                    body
                ));
            }
            Err(e) => {
                last_err = Some(e.into());
                tokio::time::sleep(Duration::from_secs(1)).await;
            }
        }
    }
    pool.release(port).await;
    Err(anyhow::anyhow!(
        "llama-server :{} unreachable after 40s: {}",
        port,
        last_err.unwrap_or_else(|| anyhow::anyhow!("connect failed"))
    ))
}
