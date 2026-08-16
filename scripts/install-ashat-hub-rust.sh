#!/usr/bin/env bash
set -euo pipefail
ROOT=/var/oled/data/AshatHub
sudo -u opc -H bash -lc 'source ~/.cargo/env; cd /var/oled/data/AshatHub; cargo build --release -p ashat-hub --bins'
sudo install -d -m 755 /usr/local/libexec/ashat-hub
sudo install -m 755 "$ROOT/target/release/ashat-hub" /usr/local/libexec/ashat-hub/ashat-hub
sudo install -m 755 "$ROOT/target/release/import_legacy" /usr/local/libexec/ashat-hub/import_legacy
if [[ ! -f /etc/ashat-ai/ashat-hub.env ]]; then
    echo 'No Rust environment file is available at /etc/ashat-ai/ashat-hub.env.' >&2
    exit 1
fi
sudo chmod 600 /etc/ashat-ai/ashat-hub.env
sudo tee /etc/systemd/system/ashat-hub-rust.service >/dev/null <<'UNIT'
[Unit]
Description=AshatHub Rust gateway and Galileo worker
After=network-online.target mariadb.service ashat-ai.service
Wants=network-online.target

[Service]
Type=simple
User=opc
Group=opc
WorkingDirectory=/var/oled/data/AshatHub
EnvironmentFile=/etc/ashat-ai/ashat-hub.env
ExecStart=/usr/local/libexec/ashat-hub/ashat-hub
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
sudo systemctl daemon-reload
sudo systemctl enable --now ashat-hub-rust.service
sleep 3
systemctl is-active ashat-hub-rust.service
