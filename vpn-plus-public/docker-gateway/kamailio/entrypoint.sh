#!/bin/sh
# Renders kamailio.cfg.tpl with the GW_* variables from .env, checks it, runs it.
set -eu
: "${GW_LAN_IP:?set GW_LAN_IP in .env}"
: "${GW_PBX_IP:?set GW_PBX_IP in .env}"
export GW_PBX_PORT="${GW_PBX_PORT:-5099}"
export GW_VPN_PORT="${GW_VPN_PORT:-5070}"
export GW_LAN_PORT="${GW_LAN_PORT:-5072}"
export GW_PBX_SIDE_PORT="${GW_PBX_SIDE_PORT:-5071}"
# A typo here used to crash-loop Kamailio (Zoiper offline). Validate, warn, fall back.
is_ipv4() { echo "$1" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; }
if [ -n "${GW_VPN_ADVERTISE_IP:-}" ] && ! is_ipv4 "$GW_VPN_ADVERTISE_IP"; then
  echo "WARNING: GW_VPN_ADVERTISE_IP='$GW_VPN_ADVERTISE_IP' is not an IPv4 address - using ${GW_LAN_IP} until .env is fixed" >&2
  GW_VPN_ADVERTISE_IP=""
fi
# ---- optional public (no-VPN) door ---------------------------------------
# GW_PUBLIC_IP may be an IPv4 address or a hostname (e.g. a dynamic-DNS name);
# a hostname is resolved once at start, so restart this container if your IP changes.
GW_PUB_PORT="${GW_PUB_PORT:-5080}"
PUB_IP=""
if [ -n "${GW_PUBLIC_IP:-}" ]; then
  if is_ipv4 "$GW_PUBLIC_IP"; then PUB_IP="$GW_PUBLIC_IP"
  else PUB_IP="$(getent ahostsv4 "$GW_PUBLIC_IP" 2>/dev/null | awk 'NR==1{print $1}')"; fi
  if ! is_ipv4 "${PUB_IP:-}"; then
    echo "WARNING: GW_PUBLIC_IP='$GW_PUBLIC_IP' is not an IPv4 address and did not resolve - public door DISABLED" >&2
    PUB_IP=""
  fi
fi
# Until the AstroWarp virtual IP is known, advertise the LAN IP (VPN socket then behaves like LAN).
export GW_VPN_ADVERTISE_IP="${GW_VPN_ADVERTISE_IP:-$GW_LAN_IP}"
export GW_PUB_PORT GW_PUBLIC_IP="$PUB_IP"
DEFS=""
if [ -n "$PUB_IP" ]; then DEFS="-A WITH_PUB"; echo "public door ON: udp/${GW_PUB_PORT} advertised as ${PUB_IP}"; fi
VARS='${GW_LAN_IP} ${GW_PBX_IP} ${GW_PBX_PORT} ${GW_VPN_PORT} ${GW_LAN_PORT} ${GW_PBX_SIDE_PORT} ${GW_VPN_ADVERTISE_IP} ${GW_PUB_PORT} ${GW_PUBLIC_IP}'
envsubst "$VARS" < /etc/kamailio/kamailio.cfg.tpl > /etc/kamailio/kamailio.cfg
kamailio -c $DEFS -f /etc/kamailio/kamailio.cfg
exec kamailio -DD -E $DEFS -f /etc/kamailio/kamailio.cfg -m 64 -M 8
