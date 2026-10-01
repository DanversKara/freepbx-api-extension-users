#!/usr/bin/env bash
# Keeps the share-card assets identical in the PBX module and the remote panel.
# The PBX module copy is the source; run this after editing it.
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
for f in qrcode.js share-card.js; do
  cp "$HERE/pbx-module/apiusers/assets/$f" "$HERE/docker-gateway/panel/static/$f"
done
echo "synced share-card assets -> docker-gateway/panel/static/"
