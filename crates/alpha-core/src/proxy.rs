use std::time::Duration;

use alpha_common::ChatRequest;
use crate::demand::VisionPool;

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

pub async fn vision_stream(pool: &VisionPool, request: &ChatRequest) -> anyhow::Result<(u16, reqwest::Response)> {
    let port = pool.acquire().await?;
    match stream_request(&format!("http://127.0.0.1:{port}/v1/chat/completions"), serde_json::json!({
        "model": "local", "messages": request.messages, "max_tokens": request.max_tokens.min(4096),
        "temperature": request.temperature, "stream": true,
    })).await {
        Ok(response) => Ok((port, response)),
        Err(error) => { pool.release(port).await; Err(error) }
    }
}

pub async fn vision_completions(pool: &VisionPool, request: &ChatRequest) -> anyhow::Result<serde_json::Value> {
    let port = pool.acquire().await?;
    let response = reqwest::Client::builder().timeout(Duration::from_secs(120)).build()?
        .post(format!("http://127.0.0.1:{port}/v1/chat/completions"))
        .json(&serde_json::json!({
            "model": "local", "messages": request.messages, "max_tokens": request.max_tokens.min(4096),
            "temperature": request.temperature, "stream": false,
        })).send().await;
    let result = match response {
        Ok(response) => { let status = response.status(); let body = response.json().await?; if status.is_success() { Ok(body) } else { Err(anyhow::anyhow!("VL :{port} returned {status}: {body}")) } }
        Err(error) => Err(error.into()),
    };
    pool.release(port).await;
    result
}
