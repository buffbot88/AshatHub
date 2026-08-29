//! Vision pool: on-demand 450M VL instances for image understanding.
//!
//! VL instances are only started when an image needs to be read.
//! They auto-shutdown after an idle timeout to free RAM for the
//! always-on text worker.

use std::process::Stdio;
use std::time::{Duration, Instant};

use alpha_common::Config;
use tokio::process::{Child, Command};
use tokio::sync::Mutex;

pub struct VisionInstance {
    pub port: u16,
    child: Child,
    born: Instant,
    last_used: Instant,
}

impl VisionInstance {
    fn alive(&mut self) -> bool {
        matches!(self.child.try_wait(), Ok(None))
    }

    fn kill(&mut self) {
        let _ = self.child.kill();
    }
}

pub struct VisionPool {
    inner: Mutex<VisionPoolInner>,
    pub config: Config,
}

struct VisionPoolInner {
    slots: Vec<Option<VisionInstance>>,
    in_use: Vec<bool>,
}

impl VisionPool {
    pub fn new(config: Config) -> Self {
        let max = config.models.max_instances as usize;
        Self {
            inner: Mutex::new(VisionPoolInner {
                slots: (0..max).map(|_| None).collect(),
                in_use: vec![false; max],
            }),
            config,
        }
    }

    fn max(&self) -> usize {
        self.config.models.max_instances as usize
    }

    fn threads(&self) -> usize {
        let cap = self.config.models.cpu_cap;
        let cores = std::thread::available_parallelism()
            .map(|n| n.get())
            .unwrap_or(1);
        ((cores as u32 * cap / 100).max(1)) as usize
    }

    fn resolve(&self, rel: &str) -> String {
        std::fs::canonicalize(rel)
            .map(|p| p.to_string_lossy().into_owned())
            .unwrap_or_else(|_| rel.to_string())
    }

    fn mem_available_mb(&self) -> u64 {
        std::fs::read_to_string("/proc/meminfo")
            .ok()
            .and_then(|s| {
                s.lines().find(|l| l.starts_with("MemAvailable:")).map(|l| {
                    l.split_whitespace()
                        .nth(1)
                        .and_then(|v| v.parse::<u64>().ok())
                        .unwrap_or(0)
                        / 1024
                })
            })
            .unwrap_or(0)
    }

    /// Spawn a VL instance into slot `idx` on port_base+idx.
    async fn spawn(&self, idx: usize, min_free_mb: u64) -> anyhow::Result<()> {
        let avail = self.mem_available_mb();
        if avail < min_free_mb {
            return Err(anyhow::anyhow!(
                "vision pool: memory pressure: {} MB free < {} MB floor, deferring spawn on :{}",
                avail,
                min_free_mb,
                self.config.pool.port_base + idx as u16
            ));
        }

        let port = self.config.pool.port_base + idx as u16;
        let model = self.resolve(&self.config.models.local_model);
        let mmproj = self.resolve(&self.config.models.mmproj);
        let threads = self.threads();
        let log = std::fs::File::create(format!("/tmp/llama-vision-{}.log", port))?;

        let child = Command::new("/home/opc/llama.cpp-src/build/bin/llama-server")
            .args([
                "--host",
                "127.0.0.1",
                "--port",
                &port.to_string(),
                "--model",
                &model,
                "--mmproj",
                &mmproj,
                "--ctx-size",
                "8192",
                "--threads",
                &threads.to_string(),
                "--ubatch-size",
                "2048",
                "--load-mode",
                "mmap+mlock",
                "--no-webui",
                "--log-disable",
            ])
            .stdout(Stdio::from(log))
            .stderr(Stdio::null())
            .kill_on_drop(true)
            .spawn()?;

        tracing::info!("vision pool: spawned VL on :{} ({} threads)", port, threads);

        let mut inner = self.inner.lock().await;
        inner.slots[idx] = Some(VisionInstance {
            port,
            child,
            born: Instant::now(),
            last_used: Instant::now(),
        });
        Ok(())
    }

    /// Acquire a VL instance for a vision request. Spawns one on-demand if needed.
    pub async fn acquire(&self) -> anyhow::Result<u16> {
        loop {
            let dead_slot = {
                let mut inner = self.inner.lock().await;
                let mut found = None;
                for i in 0..self.max() {
                    if inner.in_use[i] {
                        continue;
                    }
                    let alive = match &mut inner.slots[i] {
                        Some(inst) => inst.alive(),
                        None => false,
                    };
                    if alive {
                        inner.in_use[i] = true;
                        inner.slots[i].as_mut().unwrap().last_used = Instant::now();
                        return Ok(inner.slots[i].as_ref().unwrap().port);
                    }
                    found = Some(i);
                    break;
                }
                found
            };

            match dead_slot {
                None => {
                    // All slots busy — brief wait.
                    tokio::time::sleep(Duration::from_millis(50)).await;
                }
                Some(i) => {
                    match self.spawn(i, 700).await {
                        Ok(()) => {
                            // Reserve the newly spawned slot while it loads so
                            // concurrent image requests cannot claim it too.
                            {
                                let mut inner = self.inner.lock().await;
                                inner.in_use[i] = true;
                            }

                            // Wait for model to load (up to 30s).
                            let port = self.config.pool.port_base + i as u16;
                            let url = format!("http://127.0.0.1:{}/health", port);
                            let client = reqwest::Client::builder()
                                .timeout(Duration::from_secs(3))
                                .build()
                                .unwrap_or_default();

                            for _ in 0..30 {
                                tokio::time::sleep(Duration::from_secs(1)).await;
                                if let Ok(resp) = client.get(&url).send().await {
                                    if resp.status().is_success() {
                                        let mut inner = self.inner.lock().await;
                                        inner.slots[i].as_mut().unwrap().last_used = Instant::now();
                                        tracing::info!("vision pool: VL on :{} ready", port);
                                        return Ok(port);
                                    }
                                }
                            }
                            tracing::error!(
                                "vision pool: VL on :{} failed to load within 30s",
                                port
                            );
                            let mut inner = self.inner.lock().await;
                            inner.in_use[i] = false;
                        }
                        Err(e) => {
                            tracing::warn!("vision pool: {}", e);
                            tokio::time::sleep(Duration::from_secs(2)).await;
                        }
                    }
                }
            }
        }
    }

    pub async fn release(&self, port: u16) {
        let mut inner = self.inner.lock().await;
        for i in 0..inner.in_use.len() {
            if inner.slots[i].as_ref().map(|s| s.port) == Some(port) {
                inner.in_use[i] = false;
                if let Some(inst) = inner.slots[i].as_mut() {
                    inst.last_used = Instant::now();
                }
                return;
            }
        }
    }

    /// Count currently running VL instances.
    pub async fn running_count(&self) -> usize {
        let inner = self.inner.lock().await;
        inner.slots.iter().filter(|slot| slot.is_some()).count()
    }

    pub async fn has_running(&self) -> bool {
        self.running_count().await > 0
    }

    /// Shutdown idle VL instances that have exceeded the idle timeout.
    /// This is the key on-demand behavior: after a vision request completes,
    /// the VL stays alive for idle_timeout_secs, then gets killed to free RAM.
    pub async fn shutdown_idle(&self) {
        let timeout = Duration::from_secs(self.config.vision.idle_timeout_secs);
        let _now = Instant::now();

        let mut to_kill = Vec::new();
        {
            let mut inner = self.inner.lock().await;
            for i in 0..self.max() {
                if inner.in_use[i] || i < self.config.pool.min_instances as usize {
                    continue; // Keep baseline VL lanes hot.
                }
                if let Some(inst) = &mut inner.slots[i] {
                    if !inst.alive() {
                        // Dead process — clean up.
                        to_kill.push((i, inst.port));
                        continue;
                    }
                    if inst.last_used.elapsed() > timeout {
                        tracing::info!(
                            "vision pool: shutting down idle VL on :{} (idle for {:?})",
                            inst.port,
                            inst.last_used.elapsed()
                        );
                        to_kill.push((i, inst.port));
                    }
                }
            }
        }

        for (idx, port) in to_kill {
            let mut inner = self.inner.lock().await;
            if let Some(mut inst) = inner.slots[idx].take() {
                inst.kill();
            }
            tracing::info!("vision pool: VL on :{} stopped", port);
        }
    }

    /// Kill all VL instances (e.g. on shutdown or memory emergency).
    pub async fn stop_all(&self) {
        let mut inner = self.inner.lock().await;
        for i in 0..self.max() {
            if let Some(mut inst) = inner.slots[i].take() {
                inst.kill();
                tracing::info!("vision pool: stopped VL on :{}", inst.port);
            }
            inner.in_use[i] = false;
        }
    }

    /// Health + respawn pass for supervision.
    pub async fn supervise(&self) {
        // First, shutdown idle burst instances; baseline lanes stay hot.
        self.shutdown_idle().await;

        // Then check for dead instances that are in-use and need respawning.
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(5))
            .build()
            .unwrap_or_default();

        for i in 0..self.max() {
            let (dead, unhealthy, port) = {
                let mut inner = self.inner.lock().await;
                match &mut inner.slots[i] {
                    Some(inst) => {
                        let dead = !inst.alive();
                        let unhealthy = if dead || inst.born.elapsed() < Duration::from_secs(60) {
                            false
                        } else {
                            matches!(
                                client
                                    .get(format!("http://127.0.0.1:{}/health", inst.port))
                                    .send()
                                    .await,
                                Err(_)
                            )
                        };
                        (dead, unhealthy, inst.port)
                    }
                    None => continue, // Empty slot — not our job to fill on-demand.
                }
            };

            if dead || unhealthy {
                tracing::warn!(
                    "vision pool: instance on :{} dead/unhealthy, respawning",
                    port
                );
                {
                    let mut inner = self.inner.lock().await;
                    if let Some(mut inst) = inner.slots[i].take() {
                        inst.kill();
                    }
                    inner.in_use[i] = false;
                }
                if let Err(e) = self.spawn(i, 1200).await {
                    tracing::error!("vision pool: respawn slot {} failed: {}", i, e);
                }
            }
        }
    }
}
