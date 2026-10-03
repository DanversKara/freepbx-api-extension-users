#!/bin/sh
# Two logical interfaces on the same IP:
#   lan = what the PBX and home-Wi-Fi phones see (192.168.8.100)
#   vpn = same socket, but SDP advertises the AstroWarp virtual IP
# Kamailio picks them with "direction=vpn direction=lan" etc.
# pub = optional public (no-VPN) door, SDP advertises GW_PUBLIC_IP. Router must forward the RTP range.
# ZOIPER-PRO-TODO: add --interface=tls/${GW_LAN_IP}!<public IP> for the TLS/SRTP side,
#                  and forward UDP ${GW_RTP_MIN}-${GW_RTP_MAX} on the router to 192.168.8.100.
set -eu
: "${GW_LAN_IP:?set GW_LAN_IP in .env}"
. "$(dirname "$0")/pubip.sh"     # is_ipv4, pub_ip_now, pub_watch
# A typo here used to crash-loop Kamailio (Zoiper offline). Validate, warn, fall back.
if [ -n "${GW_VPN_ADVERTISE_IP:-}" ] && ! is_ipv4 "$GW_VPN_ADVERTISE_IP"; then
  echo "WARNING: GW_VPN_ADVERTISE_IP='$GW_VPN_ADVERTISE_IP' is not an IPv4 address - using ${GW_LAN_IP} until .env is fixed" >&2
  GW_VPN_ADVERTISE_IP=""
fi
# ---- optional public (no-VPN) door ---------------------------------------
# GW_PUBLIC_IP: empty (off), a fixed IPv4, a host name (dynamic DNS) or "auto". See pubip.sh.
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
ADV="${GW_VPN_ADVERTISE_IP:-$GW_LAN_IP}"
PUB_IF=""
[ -n "$PUB_IP" ] && PUB_IF="--interface=pub/${GW_LAN_IP}!${PUB_IP}"
exec rtpengine --foreground --log-stderr --table=-1 \
  --config-file=none \
  --interface="lan/${GW_LAN_IP}" \
  --interface="vpn/${GW_LAN_IP}!${ADV}" $PUB_IF \
  --listen-ng=127.0.0.1:22222 \
  --port-min="${GW_RTP_MIN:-40000}" --port-max="${GW_RTP_MAX:-40100}" \
  --timeout=60 --silent-timeout=3600 --final-timeout=21600 \
  --log-level="${GW_RTP_LOGLEVEL:-5}"
