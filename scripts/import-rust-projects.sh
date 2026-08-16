#!/usr/bin/env bash
set -e
sudo bash -c 'set -a; source /etc/ashat-ai/ashat-hub.env; set +a; exec sudo -u opc env ASHAT_DATABASE_URL="$ASHAT_DATABASE_URL" ASHAT_PROJECTS_ROOT="$ASHAT_PROJECTS_ROOT" /usr/local/libexec/ashat-hub/import_legacy'
