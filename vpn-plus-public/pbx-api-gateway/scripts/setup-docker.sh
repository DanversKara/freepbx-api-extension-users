#!/usr/bin/env bash
# =============================================================================
#  setup-docker.sh – first-time setup of the gateway on the Docker host
#  Run on the gateway host (the Docker machine, e.g. 192.168.8.100) as root, from the project folder:
#      bash scripts/setup-docker.sh
#  Re-running is safe: it never overwrites .env values or the SSH key.
# =============================================================================
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
GW="$HERE/docker-gateway"
cd "$GW"

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARNING: %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*"; exit 1; }

command -v docker >/dev/null || die "docker not found"
docker compose version >/dev/null 2>&1 || die "docker compose plugin not found"

say "1/5 .env"
if [ ! -f .env ]; then
  cp .env.example .env
  sed -i "s/^PANEL_SECRET_KEY=.*/PANEL_SECRET_KEY=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')/" .env
  chmod 600 .env
  echo "  created .env (edit it for PBX_REMOTE_TOKEN and GW_VPN_ADVERTISE_IP)"
else
  echo "  .env exists, leaving it alone"
fi
set -a; . ./.env; set +a
ip -4 addr | grep -q "inet ${GW_LAN_IP}/" || warn "this machine doesn't have ${GW_LAN_IP} - check GW_LAN_IP in .env"

say "2/5 SSH key for the panel -> PBX"
mkdir -p ssh && chmod 700 ssh
[ -f ssh/id_ed25519 ] || ssh-keygen -q -t ed25519 -N "" -C "panel@pbx-api-gateway" -f ssh/id_ed25519
if [ ! -s ssh/known_hosts ]; then
  ssh-keyscan -t ed25519 "$PBX_SSH_HOST" 2>/dev/null > ssh/known_hosts || true
  [ -s ssh/known_hosts ] || die "could not read the PBX host key from $PBX_SSH_HOST"
  echo "  PBX host key fingerprint seen from here:"
  ssh-keygen -lf ssh/known_hosts | sed 's/^/    /'
  echo "  Compare on the PBX with: ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub"
fi

say "3/5 Port check (nothing else may use these)"
for p in "$GW_VPN_PORT" "$GW_LAN_PORT" "$GW_PBX_SIDE_PORT" 8000; do
  if command -v ss >/dev/null && ss -lun "sport = :$p" 2>/dev/null | grep -q ":$p"; then
    docker compose ps 2>/dev/null | grep -q pbx-api-gateway || warn "port $p is already in use on this CT"
  fi
done

say "4/5 Building and starting containers"
docker compose up -d --build

say "5/5 Testing panel -> PBX"
sleep 3
if [ -z "${PBX_REMOTE_TOKEN:-}" ]; then
  warn "PBX_REMOTE_TOKEN is empty. Copy it from the PBX page, put it in .env, then: docker compose up -d"
else
  docker compose exec -T panel python -c "from app import pbx; r=pbx('list'); print('  PBX says:', 'OK,', len(r.get('users',[])), 'user(s)' if r.get('ok') else r.get('error'))" || true
fi

cat <<EOF

=============================================================================
 Put this key on the PBX (run there, as root, in the project folder):

   bash scripts/install-pbx.sh --add-key "$(cat ssh/id_ed25519.pub)"

 Then here:  docker compose restart panel   and re-run this script to test.
 Logs:       docker compose logs -f kamailio   (or rtpengine / panel)
=============================================================================
EOF
