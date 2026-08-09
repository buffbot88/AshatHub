//! Demand pool: spawn-on-demand llama-server instances, min N / max M, CPU-capped.

use std::process::Stdio;
use std::time::{Duration, Instant};

use alpha_common::Config;
use tokio::process::{Child, Command};
use tokio::sync::Mutex;

pub struct LlamaInstance {
    pub port: u16,
    child: Child,
    born: Instant,
}

impl LlamaInstance {
    /// True if the child process is still alive (hasn't exited).
    fn alive(&mut self) -> bool {
        matches!(self.child.try_wait(), Ok(None))
    }

    fn kill(&mut self) {
        let _ = self.child.kill();
    }
}

pub struct InstancePool {
    inner: Mutex<PoolInner>,
    pub config: Config,
}

struct PoolInner {
    slots: Vec<Option<LlamaInstance>>,
    in_use: Vec<bool>,
}

impl InstancePool {
    pub fn new(config: Config) -> Self {
        let max = config.models.max_instances as usize;
        Self {
            inner: Mutex::new(PoolInner {
                slots: (0..max).map(|_| None).collect(),
                in_use: vec![false; max],
            }),
            config,
        }
    }

    fn max(&self) -> usize {
        self.config.models.max_instances as usize
    }

    /// cpu_cap is a percent of available cores, min 1 thread.
    fn threads(&self) -> usize {
        let cap = self.config.models.cpu_cap;
        let cores = std::thread::available_parallelism().map(|n| n.get()).unwrap_or(1);
        ((cores as u32 * cap / 100).max(1)) as usize
    }

    fn resolve(&self, rel: &str) -> String {
        std::fs::canonicalize(rel)
            .map(|p| p.to_string_lossy().into_owned())
            .unwrap_or_else(|_| rel.to_string())
    }

    /// Free RAM in MB from /proc/meminfo (MemAvailable includes reclaimable cache).
    fn mem_available_mb(&self) -> u64 {
        std::fs::read_to_string("/proc/meminfo")
            .ok()
            .and_then(|s| {
                s.lines().find(|l| l.starts_with("MemAvailable:")).map(|l| {
                    l.split_whitespace().nth(1).and_then(|v| v.parse::<u64>().ok()).unwrap_or(0) / 1024
                })
            })
            .unwrap_or(0)
    }

    /// Spawn llama-server into slot `idx` on port_base+idx. Logs to /tmp/llama-<port>.log.
    /// Refuses to spawn when free RAM is below `min_free_mb` so the pool
    /// degrades gracefully (queue absorbs demand) instead of OOM-killing
    /// an instance mid-inference.
    async fn spawn(&self, idx: usize, min_free_mb: u64) -> anyhow::Result<()> {
        let avail = self.mem_available_mb();
        if avail < min_free_mb {
            return Err(anyhow::anyhow!(
                "memory pressure: {} MB free < {} MB floor, deferring spawn on :{}",
                avail, min_free_mb, self.config.pool.port_base + idx as u16
            ));
        }        let port = self.config.pool.port_base + idx as u16;
        let model = self.resolve(&self.config.models.local_model);
        let mmproj = self.resolve(&self.config.models.mmproj);
        let threads = self.threads();
        let log = std::fs::File::create(format!("/tmp/llama-{}.log", port))?;
        let child = Command::new("/home/opc/llama.cpp-src/build/bin/llama-server")
            .args([
                "--host", "127.0.0.1",
                "--port", &port.to_string(),
                "--model", &model,
                "--mmproj", &mmproj,
                "--ctx-size", "8192",
                "--threads", &threads.to_string(),
                "--ubatch-size", "2048",
                "--load-mode", "mmap+mlock",
                "--no-webui",
                "--log-disable",
            ])
            .stdout(Stdio::from(log))
            .stderr(Stdio::null())
            .kill_on_drop(true)
            .spawn()?;
        tracing::info!("spawned llama-server on :{} ({} thread)", port, threads);
        let mut inner = self.inner.lock().await;
        inner.slots[idx] = Some(LlamaInstance {
            port,
            child,
            born: Instant::now(),
        });
        Ok(())
    }

    /// Ensure at least min_instances are running. Call once at startup.
    pub async fn ensure_min(&self) -> anyhow::Result<()> {
        let min = self.config.pool.min_instances as usize;
        for i in 0..min {
            let need = {
                let mut inner = self.inner.lock().await;
                match &mut inner.slots[i] {
                    Some(inst) => !inst.alive(),
                    None => true,
                }
            };
            if need {
                self.spawn(i, 700).await?;
            }
        }
        Ok(())
    }

    /// Claim a free instance, spawning/respawning on demand. Caller holds a queue
    /// permit (<= max instances), so a free slot is guaranteed modulo a dead child.
    pub async fn acquire(&self) -> anyhow::Result<u16> {
        loop {
            // Find a free or dead slot; drop the guard before spawning.
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
                        return Ok(inner.slots[i].as_ref().unwrap().port);
                    }
                    found = Some(i);
                    break;
                }
                found
            };
            match dead_slot {
                None => {
                    // All slots busy (permits exceeded max) — brief wait.
                    tokio::time::sleep(Duration::from_millis(25)).await;
                }
                Some(i) => {
                    // Defer under memory pressure (1200 MB floor): keep the
                    // request queued rather than OOM-kill an instance.
                    match self.spawn(i, 1200).await {
                        Ok(()) => {
                            let mut inner = self.inner.lock().await;
                            inner.in_use[i] = true;
                            return Ok(inner.slots[i].as_ref().unwrap().port);
                        }
                        Err(e) => {
                            tracing::warn!("{}", e);
                            tokio::time::sleep(Duration::from_millis(2000)).await;
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
                return;
            }
        }
    }

    /// Health + respawn pass. Exited children are respawned; alive-but-unresponsive
    /// instances (past a cold-start grace period) are killed and respawned.
    /// Empty slots under min_instances are filled so the pool self-heals.
    pub async fn supervise(&self) {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(5))
            .build()
            .unwrap_or_default();
        let min = self.config.pool.min_instances as usize;
        for i in 0..self.max() {
            let (dead, unhealthy, port, missing) = {
                let mut inner = self.inner.lock().await;
                match &mut inner.slots[i] {
                    Some(inst) => {
                        let dead = !inst.alive();
                        let unhealthy = if dead || inst.born.elapsed() < Duration::from_secs(60) {
                            false
                        } else {
                            // Hung process check: /health must answer.
                            matches!(
                                client
                                    .get(format!("http://127.0.0.1:{}/health", inst.port))
                                    .send()
                                    .await,
                                Err(_)
                            )
                        };
                        (dead, unhealthy, inst.port, false)
                    }
                    None => (false, false, 0, i < min),
                }
            };
            if missing {
                // Min-slot fill: lower floor (700 MB) — keep at least one
                // instance unless the box is truly starved.
                if let Err(e) = self.spawn(i, 700).await {
                    tracing::error!("fill slot {} failed: {}", i, e);
                }
            } else if dead || unhealthy {
                tracing::warn!(
                    "instance {} (slot {}) dead/unhealthy, respawning",
                    port, i
                );
                {
                    let mut inner = self.inner.lock().await;
                    if let Some(mut inst) = inner.slots[i].take() {
                        inst.kill();
                    }
                }
                if let Err(e) = self.spawn(i, 1200).await {
                    tracing::error!("respawn slot {} failed: {}", i, e);
                }
            }
        }
    }
}
