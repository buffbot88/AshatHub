#!/bin/bash
export PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright
export HOME=/tmp
exec /usr/bin/node /home/opc/projects/AshatHub/tools/visual/screenshot.mjs "$@"
