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
# Until the AstroWarp virtual IP is known, advertise the LAN IP (VPN socket then behaves like LAN).
export GW_VPN_ADVERTISE_IP="${GW_VPN_ADVERTISE_IP:-$GW_LAN_IP}"
VARS='${GW_LAN_IP} ${GW_PBX_IP} ${GW_PBX_PORT} ${GW_VPN_PORT} ${GW_LAN_PORT} ${GW_PBX_SIDE_PORT} ${GW_VPN_ADVERTISE_IP}'
envsubst "$VARS" < /etc/kamailio/kamailio.cfg.tpl > /etc/kamailio/kamailio.cfg
kamailio -c -f /etc/kamailio/kamailio.cfg
exec kamailio -DD -E -f /etc/kamailio/kamailio.cfg -m 64 -M 8
