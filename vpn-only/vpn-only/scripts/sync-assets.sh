#!/usr/bin/env bash
# Keeps the share-card and live-view assets identical in the PBX module and the remote panel.
# The PBX module copy is the source; run this after editing it.
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
for f in qrcode.js share-card.js live-view.js; do
  cp "$HERE/pbx-module/apiusers/assets/$f" "$HERE/docker-gateway/panel/static/$f"
done
mkdir -p "$HERE/docker-gateway/portal/static"
for f in qrcode.js share-card.js; do
  cp "$HERE/pbx-module/apiusers/assets/$f" "$HERE/docker-gateway/portal/static/$f"
done
echo "synced share-card + live-view assets -> docker-gateway/panel/static/"
