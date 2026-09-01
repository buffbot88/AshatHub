//! Proxy: route chat requests to the appropriate worker.
//!
//! - Text-only requests → always-on 350M text worker (intent, planning, conversation)
//! - Image requests → on-demand 450M VL vision pool

use std::time::Duration;

use alpha_common::ChatRequest;

use crate::demand::VisionPool;
use crate::text_worker::TextWorker;

async fn stream_request(url: &str, payload: serde_json::Value) -> anyhow::Result<reqwest::Response> {
    let response = reqwest::Client::builder()
        .timeout(Duration::from_secs(120))
        .build()?
        .post(url)
        .json(&payload)
        .send()
        .await?;
    if !response.status().is_success() {
        return Err(anyhow::anyhow!("worker returned {}", response.status()));
    }
    Ok(response)
}

/// Send a text-only chat completion to the always-on 350M text worker.
pub async fn text_worker_completions(
    worker: &TextWorker,
    request: &ChatRequest,
) -> anyhow::Result<serde_json::Value> {
    let url = worker.completions_url();
    let messages: Vec<_> = request
        .messages
        .iter()
        .filter(|message| message.role != "system")
        .cloned()
        .collect();
    let payload = serde_json::json!({
        "model": "local",
        "messages": messages,
        // The 350M worker has a 4096-token context; keep local chat small and
        // leave room for the conversation instead of the coding system prompt.
        "max_tokens": request.max_tokens.min(1024),
        "temperature": request.temperature,
        "stream": false,
    });

    let client = reqwest::Client::builder()
        .timeout(Duration::from_secs(120))
        .build()?;

    let resp = client.post(&url).json(&payload).send().await?;
    let status = resp.status();

    if status == reqwest::StatusCode::SERVICE_UNAVAILABLE {
        return Err(anyhow::anyhow!(
            "text worker :{} loading model",
            worker.port()
        ));
    }

    let body: serde_json::Value = resp.json().await?;

    if status.is_success() {
        return Ok(body);
    }

    Err(anyhow::anyhow!(
        "text worker :{} returned {}: {}",
        worker.port(),
        status,
        body
    ))
}

pub async fn text_worker_stream(
    worker: &TextWorker,
    request: &ChatRequest,
) -> anyhow::Result<reqwest::Response> {
    let messages: Vec<_> = request.messages.iter().filter(|message| message.role != "system").cloned().collect();
    stream_request(&worker.completions_url(), serde_json::json!({
        "model": "local", "messages": messages, "max_tokens": request.max_tokens.min(1024),
        "temperature": request.temperature, "stream": true,
    })).await
}

/// Send a vision chat completion to the on-demand VL pool.
/// The pool auto-starts a VL instance if none is running.
pub async fn vision_stream(
    pool: &VisionPool,
    request: &ChatRequest,
) -> anyhow::Result<(u16, reqwest::Response)> {
    let port = pool.acquire().await?;
    let response = stream_request(&format!("http://127.0.0.1:{}/v1/chat/completions", port), serde_json::json!({
        "model": "local", "messages": request.messages, "max_tokens": request.max_tokens.min(4096),
        "temperature": request.temperature, "stream": true,
    })).await;
    match response {
        Ok(response) => Ok((port, response)),
        Err(error) => {
            pool.release(port).await;
            Err(error)
        }
    }
}

pub async fn vision_completions(
    pool: &VisionPool,
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

    // Retry on cold start (503 while model loads).
    let mut last_err: Option<anyhow::Error> = None;
    for _attempt in 0..40 {
        match client.post(&url).json(&payload).send().await {
            Ok(resp) => {
                let status = resp.status();
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
                    "VL :{} returned {}: {}",
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
        "VL :{} unreachable after 40s: {}",
        port,
        last_err.unwrap_or_else(|| anyhow::anyhow!("connect failed"))
    ))
}
