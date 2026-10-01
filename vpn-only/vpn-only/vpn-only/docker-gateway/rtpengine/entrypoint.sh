#!/bin/sh
# Two logical interfaces on the same IP:
#   lan = what the PBX and home-Wi-Fi phones see (192.168.8.100)
#   vpn = same socket, but SDP advertises the AstroWarp virtual IP
# Kamailio picks them with "direction=vpn direction=lan" etc.
# ZOIPER-PRO-TODO: add --interface=pub/${GW_LAN_IP}!<public IP> for the TLS/SRTP side,
#                  and forward UDP ${GW_RTP_MIN}-${GW_RTP_MAX} on the router to 192.168.8.100.
set -eu
: "${GW_LAN_IP:?set GW_LAN_IP in .env}"
# A typo here used to crash-loop Kamailio (Zoiper offline). Validate, warn, fall back.
is_ipv4() { echo "$1" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; }
if [ -n "${GW_VPN_ADVERTISE_IP:-}" ] && ! is_ipv4 "$GW_VPN_ADVERTISE_IP"; then
  echo "WARNING: GW_VPN_ADVERTISE_IP='$GW_VPN_ADVERTISE_IP' is not an IPv4 address - using ${GW_LAN_IP} until .env is fixed" >&2
  GW_VPN_ADVERTISE_IP=""
fi
ADV="${GW_VPN_ADVERTISE_IP:-$GW_LAN_IP}"
exec rtpengine --foreground --log-stderr --table=-1 \
  --config-file=none \
  --interface="lan/${GW_LAN_IP}" \
  --interface="vpn/${GW_LAN_IP}!${ADV}" \
  --listen-ng=127.0.0.1:22222 \
  --port-min="${GW_RTP_MIN:-40000}" --port-max="${GW_RTP_MAX:-40100}" \
  --timeout=60 --silent-timeout=3600 --final-timeout=21600 \
  --log-level="${GW_RTP_LOGLEVEL:-5}"
