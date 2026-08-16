//! Text worker: always-on 350M instance for intent classification,
//! build planning, conversation, and brainstorming.
//!
//! This is the "master worker" that stays alive permanently.
//! It handles all text-only tasks without the overhead of the VL model.

use std::process::Stdio;
use std::time::Duration;

use alpha_common::Config;
use tokio::process::{Child, Command};
use tokio::sync::Mutex;

pub struct TextWorker {
    inner: Mutex<Option<Child>>,
    config: Config,
}

impl TextWorker {
    pub fn new(config: Config) -> Self {
        Self {
            inner: Mutex::new(None),
            config,
        }
    }

    fn resolve(&self, rel: &str) -> String {
        std::fs::canonicalize(rel)
            .map(|p| p.to_string_lossy().into_owned())
            .unwrap_or_else(|_| rel.to_string())
    }

    fn threads(&self) -> usize {
        let cap = self.config.models.cpu_cap;
        let cores = std::thread::available_parallelism()
            .map(|n| n.get())
            .unwrap_or(1);
        ((cores as u32 * cap / 100).max(1)) as usize
    }

    /// Check if the text worker is alive and responding.
    pub async fn is_alive(&self) -> bool {
        let port = self.config.text_worker.port;
        let mut inner = self.inner.lock().await;

        // Check if process is still running.
        let process_alive = match inner.as_mut() {
            Some(child) => matches!(child.try_wait(), Ok(None)),
            None => false,
        };

        if !process_alive {
            return false;
        }

        // Check if it responds to health checks.
        drop(inner);
        match reqwest::Client::builder()
            .timeout(Duration::from_secs(3))
            .build()
            .unwrap_or_default()
            .get(format!("http://127.0.0.1:{}/health", port))
            .send()
            .await
        {
            Ok(resp) => resp.status().is_success(),
            Err(_) => false,
        }
    }

    /// Start the text worker. Returns true if already running or started successfully.
    pub async fn ensure_running(&self) -> anyhow::Result<()> {
        if self.is_alive().await {
            return Ok(());
        }

        // Clean up only a child process owned by this worker. Never issue
        // `kill -9 0`: on Unix that targets the current process group.
        let stale_child = {
            let mut inner = self.inner.lock().await;
            inner.take()
        };
        if let Some(mut child) = stale_child {
            let _ = child.kill().await;
        }

        let port = self.config.text_worker.port;
        let model = self.resolve(&self.config.text_worker.model);
        let threads = self.threads();
        let ctx_size = self.config.text_worker.ctx_size;

        let log = std::fs::File::create(format!("/tmp/llama-text-{}.log", port))?;

        let child = Command::new("/home/opc/llama.cpp-src/build/bin/llama-server")
            .args([
                "--host",
                "127.0.0.1",
                "--port",
                &port.to_string(),
                "--model",
                &model,
                "--ctx-size",
                &ctx_size.to_string(),
                "--threads",
                &threads.to_string(),
                "--ubatch-size",
                "1024",
                "--load-mode",
                "mmap",
                "--no-webui",
                "--log-disable",
            ])
            .stdout(Stdio::from(log))
            .stderr(Stdio::null())
            .kill_on_drop(true)
            .spawn()?;

        tracing::info!(
            "started text worker on :{} ({} threads, ctx={})",
            port,
            threads,
            ctx_size
        );

        {
            let mut inner = self.inner.lock().await;
            *inner = Some(child);
        }

        // Wait for the model to load (up to 30s). The mutex must be
        // released before is_alive() checks it.
        for _ in 0..30 {
            tokio::time::sleep(Duration::from_secs(1)).await;
            if self.is_alive().await {
                tracing::info!("text worker on :{} ready", port);
                return Ok(());
            }
        }

        Err(anyhow::anyhow!(
            "text worker on :{} failed to start within 30s",
            port
        ))
    }

    /// Stop the text worker.
    pub async fn stop(&self) {
        let mut inner = self.inner.lock().await;
        if let Some(mut child) = inner.take() {
            let _ = child.kill();
            tracing::info!("stopped text worker");
        }
    }

    /// Get the port for the text worker.
    pub fn port(&self) -> u16 {
        self.config.text_worker.port
    }

    /// Health check endpoint URL.
    pub fn health_url(&self) -> String {
        format!("http://127.0.0.1:{}/health", self.config.text_worker.port)
    }

    /// Chat completions endpoint URL.
    pub fn completions_url(&self) -> String {
        format!(
            "http://127.0.0.1:{}/v1/chat/completions",
            self.config.text_worker.port
        )
    }
}
