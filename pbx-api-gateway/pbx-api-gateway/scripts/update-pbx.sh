#!/usr/bin/env bash
# =============================================================================
#  update-pbx.sh – update the API Users module on the PBX to this copy of the repo
#  Run ON the PBX as root, from the project folder:   bash scripts/update-pbx.sh
#
#  Copies the module files, fixes ownership, and regenerates the Asterisk config
#  from the saved users (needed when a new version changes the generated dialplan).
#  Users, passwords, permissions and the remote token are kept: they live in the DB.
#  Calls in progress are not dropped (pjsip + dialplan reload only).
# =============================================================================
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
WEBROOT="${WEBROOT:-/var/www/html}"
DST="$WEBROOT/admin/modules/apiusers"

[ "$(id -u)" = 0 ] || { echo "run as root"; exit 1; }
[ -d "$DST" ] || { echo "module not installed yet - run scripts/install-pbx.sh first"; exit 1; }

echo "==> logic tests"
php "$HERE/tests/engine_test.php" | tail -1
php "$HERE/tests/live_test.php" | tail -1
php "$HERE/tests/features_test.php" | tail -1
php "$HERE/tests/alerts_test.php" | tail -1
[ -f "$HERE/tests/pubip_test.php" ] && php "$HERE/tests/pubip_test.php" | tail -1

echo "==> copying module to $DST"
cp -a "$HERE/pbx-module/apiusers/." "$DST/"
install -m 755 -o root -g root "$HERE/pbx-module/apiusers/bin/apiusers-remote" /usr/local/sbin/apiusers-remote
install -m 755 -o root -g root "$HERE/pbx-module/apiusers/bin/apiusers-presence" /usr/local/sbin/apiusers-presence
# Live view: record sign-ins / sign-outs once a minute, even when nobody has the page open
cat > /etc/cron.d/apiusers <<'CRON'
# apiusers module (API Users): sign-in history for the Live view
* * * * * asterisk /usr/local/sbin/apiusers-presence >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/apiusers
fwconsole chown >/dev/null

# A new version number in module.xml makes FreePBX refuse to load the module until it is
# installed again ("Unable to locate the FreePBX BMO Class 'Apiusers'"). Safe to repeat:
# install() keeps users/settings/token and rewrites the generated config.
echo "==> registering the module version with FreePBX"
fwconsole ma install apiusers >/dev/null || { echo "ERROR: fwconsole ma install apiusers failed"; exit 1; }
fwconsole chown >/dev/null

echo "==> regenerating Asterisk config from saved users"
php -r '
  $bootstrap_settings = ["freepbx_auth" => false];
  ob_start(); include "/etc/freepbx.conf"; ob_end_clean();
  $m = FreePBX::Apiusers(); $st = $m->loadState(); $m->apply($st, $st);
  echo "   ", count($st["users"]), " user(s) written\n";'

asterisk -rx "dialplan show apiusers-netcheck" >/dev/null 2>&1 && echo "   OK  dialplan loaded" || echo "   WARNING: dialplan not loaded"
echo "Done. Refresh the API Users page (Ctrl+F5)."
