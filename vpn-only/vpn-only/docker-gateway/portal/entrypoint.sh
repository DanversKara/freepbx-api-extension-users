#!/bin/sh
# Same idea as the admin panel: copy the (root-only, read-only mounted) SSH key and the PBX host
# fingerprint to a private folder owned by the unprivileged "portal" user, then start gunicorn.
# This key is a DIFFERENT key from the panel's: on the PBX it only runs "apiusers-remote --portal".
set -eu
mkdir -p /run/portal
cp /ssh/id_ed25519 /run/portal/id_ed25519
cp /ssh/known_hosts /run/portal/known_hosts
chown -R portal /run/portal && chmod 700 /run/portal
chmod 600 /run/portal/id_ed25519 && chmod 644 /run/portal/known_hosts
[ -n "${PORTAL_TOKEN:-}" ] || echo "WARNING: PORTAL_TOKEN is empty - copy it from the PBX 'API Users' page (Settings > User portal)"
exec su -s /bin/sh portal -c "exec gunicorn -w 2 -b 0.0.0.0:8010 --access-logfile - app:app"
