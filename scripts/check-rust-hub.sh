#!/usr/bin/env bash
curl -sS http://127.0.0.1:3100/health; echo
sudo journalctl -u ashat-hub-rust.service -n 30 --no-pager
