#!/bin/sh
# ssh refuses keys that are group/world readable, and the mounted /ssh folder is
# root-only. The web server runs as the unprivileged "panel" user, so copy BOTH
# the key and the PBX host fingerprint (known_hosts) to a private spot it owns.
# (Bug fixed Oct 2026: only the key was copied -> "No ED25519 host key is known".)
set -eu
mkdir -p /run/panel
cp /ssh/id_ed25519 /run/panel/id_ed25519
cp /ssh/known_hosts /run/panel/known_hosts
chown -R panel /run/panel && chmod 700 /run/panel
chmod 600 /run/panel/id_ed25519 && chmod 644 /run/panel/known_hosts
export PBX_KNOWN_HOSTS=/run/panel/known_hosts
[ -n "${PBX_REMOTE_TOKEN:-}" ] || echo "WARNING: PBX_REMOTE_TOKEN is empty - copy it from the PBX 'API Users' page"
exec su -s /bin/sh panel -c "PBX_KNOWN_HOSTS=/run/panel/known_hosts exec gunicorn -w 2 -b 0.0.0.0:8000 --access-logfile - app:app"
