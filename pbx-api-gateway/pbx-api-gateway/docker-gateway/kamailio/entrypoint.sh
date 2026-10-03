#!/bin/sh
# Renders kamailio.cfg.tpl with the GW_* variables from .env, checks it, runs it.
set -eu
: "${GW_LAN_IP:?set GW_LAN_IP in .env}"
: "${GW_PBX_IP:?set GW_PBX_IP in .env}"
export GW_PBX_PORT="${GW_PBX_PORT:-5099}"
export GW_VPN_PORT="${GW_VPN_PORT:-5070}"
export GW_LAN_PORT="${GW_LAN_PORT:-5072}"
export GW_PBX_SIDE_PORT="${GW_PBX_SIDE_PORT:-5071}"
. "$(dirname "$0")/pubip.sh"     # is_ipv4, pub_ip_now, pub_watch
# A typo here used to crash-loop Kamailio (Zoiper offline). Validate, warn, fall back.
if [ -n "${GW_VPN_ADVERTISE_IP:-}" ] && ! is_ipv4 "$GW_VPN_ADVERTISE_IP"; then
  echo "WARNING: GW_VPN_ADVERTISE_IP='$GW_VPN_ADVERTISE_IP' is not an IPv4 address - using ${GW_LAN_IP} until .env is fixed" >&2
  GW_VPN_ADVERTISE_IP=""
fi
# ---- optional public (no-VPN) door ---------------------------------------
# GW_PUBLIC_IP: empty (off), a fixed IPv4, a host name (dynamic DNS) or "auto". See pubip.sh.
GW_PUB_PORT="${GW_PUB_PORT:-5080}"
PUB_IP=""
if [ -n "${GW_PUBLIC_IP:-}" ]; then
  PUB_IP="$(pub_ip_now)"
  if ! is_ipv4 "${PUB_IP:-}"; then
    echo "WARNING: GW_PUBLIC_IP='$GW_PUBLIC_IP' gave no IPv4 address right now - public door OFF until it does" >&2
    PUB_IP=""
  fi
fi
# Follow a changing public IP (host name / auto): restarts this container when it changes.
if pub_needs_watch; then echo "watching GW_PUBLIC_IP=$GW_PUBLIC_IP for changes (every ${GW_PUBLIC_IP_CHECK:-60}s)"; pub_watch "$PUB_IP" $$ & fi
# Until the AstroWarp virtual IP is known, advertise the LAN IP (VPN socket then behaves like LAN).
export GW_VPN_ADVERTISE_IP="${GW_VPN_ADVERTISE_IP:-$GW_LAN_IP}"
export GW_PUB_PORT GW_PUBLIC_IP="$PUB_IP"
DEFS=""
if [ -n "$PUB_IP" ]; then DEFS="-A WITH_PUB"; echo "public door ON: udp/${GW_PUB_PORT} advertised as ${PUB_IP}"; fi
VARS='${GW_LAN_IP} ${GW_PBX_IP} ${GW_PBX_PORT} ${GW_VPN_PORT} ${GW_LAN_PORT} ${GW_PBX_SIDE_PORT} ${GW_VPN_ADVERTISE_IP} ${GW_PUB_PORT} ${GW_PUBLIC_IP}'
envsubst "$VARS" < /etc/kamailio/kamailio.cfg.tpl > /etc/kamailio/kamailio.cfg
kamailio -c $DEFS -f /etc/kamailio/kamailio.cfg
exec kamailio -DD -E $DEFS -f /etc/kamailio/kamailio.cfg -m 64 -M 8
