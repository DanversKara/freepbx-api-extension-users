#!/usr/bin/env bash
# =============================================================================
#  install-pbx.sh – installs the "API Users" FreePBX module on the PBX
#  Run ON the PBX (192.168.8.220) as root, from the project folder:
#      bash scripts/install-pbx.sh
#  Add the Docker panel's SSH key (printed by setup-docker.sh) later with:
#      bash scripts/install-pbx.sh --add-key "ssh-ed25519 AAAA... panel@pbx-api-gateway"
#
#  Safe to re-run: every step checks before changing anything.
#  What it touches (and how to undo) is listed in CLAUDE.md -> "PBX footprint".
# =============================================================================
set -euo pipefail

GATEWAY_IP="${GATEWAY_IP:-192.168.8.100}"
WEBROOT="${WEBROOT:-/var/www/html}"
AST=/etc/asterisk
HERE="$(cd "$(dirname "$0")/.." && pwd)"
MOD_SRC="$HERE/pbx-module/apiusers"
MOD_DST="$WEBROOT/admin/modules/apiusers"
REMOTE_USER=apiremote
ADD_KEY=""
ASSUME_YES=0

while [ $# -gt 0 ]; do
  case "$1" in
    --add-key) ADD_KEY="$2"; shift 2 ;;
    -y|--yes)  ASSUME_YES=1; shift ;;
    *) echo "unknown option $1"; exit 1 ;;
  esac
done

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARNING: %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*"; exit 1; }

[ "$(id -u)" = 0 ] || die "run as root"
command -v fwconsole >/dev/null || die "fwconsole not found - is this the PBX?"
[ -d "$WEBROOT/admin/modules" ] || die "$WEBROOT/admin/modules not found (set WEBROOT=...)"

# ---------------------------------------------------------------------------
add_key() {
  local key="$1"
  [[ "$key" =~ ^ssh-ed25519\ [A-Za-z0-9+/=]+ ]] || die "that doesn't look like an ed25519 public key"
  local home; home=$(getent passwd "$REMOTE_USER" | cut -d: -f6)
  [ -n "$home" ] || die "user $REMOTE_USER missing - run the installer without --add-key first"
  install -d -m 700 -o "$REMOTE_USER" -g "$REMOTE_USER" "$home/.ssh"
  local line="from=\"$GATEWAY_IP\",restrict,command=\"sudo -n -u asterisk /usr/local/sbin/apiusers-remote\" $key"
  # Only one panel key at a time: replace whatever was there.
  printf '%s\n' "$line" > "$home/.ssh/authorized_keys"
  chown "$REMOTE_USER:$REMOTE_USER" "$home/.ssh/authorized_keys"; chmod 600 "$home/.ssh/authorized_keys"
  say "Panel key installed for $REMOTE_USER (only usable from $GATEWAY_IP, only runs apiusers-remote)"
}

if [ -n "$ADD_KEY" ]; then add_key "$ADD_KEY"; exit 0; fi

# ---------------------------------------------------------------------------
say "1/7 Copying module to $MOD_DST"
[ -f "$MOD_SRC/module.xml" ] || die "module source not found at $MOD_SRC"
mkdir -p "$MOD_DST"
cp -a "$MOD_SRC/." "$MOD_DST/"

say "2/7 Creating empty generated files (so the includes never break)"
for f in pjsip_apiusers.conf extensions_apiusers.conf; do
  [ -f "$AST/$f" ] || printf '; filled in by the apiusers module\n' > "$AST/$f"
  chown asterisk:asterisk "$AST/$f"; chmod 640 "$AST/$f"
done

say "3/7 Hooking into FreePBX custom files"
# PJSIP: FreePBX 17's pjsip.conf includes pjsip_custom.conf; fall back to the endpoint custom file.
PJ_HOOK=pjsip_custom.conf
grep -q 'pjsip_custom.conf' "$AST/pjsip.conf" 2>/dev/null || PJ_HOOK=pjsip.endpoint_custom.conf
touch "$AST/$PJ_HOOK"
if ! grep -q '#include pjsip_apiusers.conf' "$AST/$PJ_HOOK"; then
  printf '\n; --- apiusers module (API Users) ---\n#include pjsip_apiusers.conf\n' >> "$AST/$PJ_HOOK"
  echo "  added include to $PJ_HOOK"
else
  echo "  $PJ_HOOK already includes it"
fi
EXT="$AST/extensions_custom.conf"; touch "$EXT"
if ! grep -q '#include extensions_apiusers.conf' "$EXT"; then
  printf '\n; --- apiusers module (API Users) ---\n#include extensions_apiusers.conf\n' >> "$EXT"
  echo "  added include to extensions_custom.conf"
fi
if ! grep -q 'include => apiusers-reach' "$EXT"; then
  if grep -q '^\[from-internal-custom\]' "$EXT"; then
    sed -i '0,/^\[from-internal-custom\]/s//[from-internal-custom]\ninclude => apiusers-reach   ; apiusers: dial 88xx to ring an API user/' "$EXT"
  else
    printf '\n[from-internal-custom]\ninclude => apiusers-reach   ; apiusers: dial 88xx to ring an API user\n' >> "$EXT"
  fi
  echo "  phones can now dial 88xx (apiusers-reach)"
fi
chown asterisk:asterisk "$AST/$PJ_HOOK" "$EXT"

say "4/7 Installing the module in FreePBX"
fwconsole chown >/dev/null
fwconsole ma install apiusers || die "module install failed (unsigned local modules may need: /root/sig-fix)"
fwconsole reload >/dev/null

say "5/7 Remote control: $REMOTE_USER user + forced command"
install -m 755 -o root -g root "$MOD_SRC/bin/apiusers-remote" /usr/local/sbin/apiusers-remote
install -m 755 -o root -g root "$MOD_SRC/bin/apiusers-presence" /usr/local/sbin/apiusers-presence
printf '# apiusers module (API Users): sign-in history for the Live view\n* * * * * asterisk /usr/local/sbin/apiusers-presence >/dev/null 2>&1\n' > /etc/cron.d/apiusers
chmod 644 /etc/cron.d/apiusers
command -v sudo >/dev/null || { apt-get update -qq && apt-get install -y -qq sudo; }
if ! id "$REMOTE_USER" >/dev/null 2>&1; then
  useradd --system --create-home --home-dir /var/lib/$REMOTE_USER --shell /bin/sh "$REMOTE_USER"
  passwd -l "$REMOTE_USER" >/dev/null
fi
SUDOERS=/etc/sudoers.d/apiusers-remote
echo "$REMOTE_USER ALL=(asterisk) NOPASSWD: /usr/local/sbin/apiusers-remote" > "$SUDOERS.tmp"
chmod 440 "$SUDOERS.tmp"
visudo -cf "$SUDOERS.tmp" >/dev/null && mv "$SUDOERS.tmp" "$SUDOERS" || die "sudoers check failed"
if grep -qiE '^\s*(AllowUsers|AllowGroups)' /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null; then
  warn "sshd has AllowUsers/AllowGroups. Add '$REMOTE_USER' there or the panel can't log in."
fi

say "6/7 Restarting Asterisk to open the new UDP ${GATEWAY_IP:+gateway }transport (5099)"
if [ "$ASSUME_YES" = 1 ]; then ans=y; else
  read -r -p "  This drops calls in progress. Restart Asterisk now? [y/N] " ans; fi
if [[ "$ans" =~ ^[Yy] ]]; then
  fwconsole restart >/dev/null && echo "  restarted"
else
  warn "Skipped. Run 'fwconsole restart' later - API users won't work until then."
fi

say "7/7 Checks"
asterisk -rx "pjsip show transports" | grep -q transport-apiusers \
  && echo "  OK  transport-apiusers is listening" \
  || warn "transport-apiusers not loaded yet (restart Asterisk)"
asterisk -rx "dialplan show apiusers-pre" >/dev/null 2>&1 \
  && echo "  OK  dialplan loaded" || warn "dialplan not loaded"
TOKEN=$(php -r '$bootstrap_settings=["freepbx_auth"=>false]; ob_start(); include "/etc/freepbx.conf"; ob_end_clean(); echo FreePBX::Apiusers()->loadState()["settings"]["remote_token"];' 2>/dev/null || true)

cat <<EOF

=============================================================================
 Done. Open the PBX web page -> Applications -> API Users.

 Remote token (put in the Docker .env as PBX_REMOTE_TOKEN):
   ${TOKEN:-<see the API Users page, Settings section>}

 Next: on the Docker CT run  bash scripts/setup-docker.sh
       then back here:       bash scripts/install-pbx.sh --add-key "<key it prints>"
=============================================================================
EOF
