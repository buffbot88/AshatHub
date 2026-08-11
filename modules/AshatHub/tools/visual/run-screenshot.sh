#!/bin/bash
export PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright
export HOME=/tmp
exec /usr/bin/node /home/opc/AshatPlatform/modules/AshatHub/tools/visual/screenshot.mjs "$@"
