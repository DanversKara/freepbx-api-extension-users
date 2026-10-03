# Public IP helpers, shared by kamailio/ and rtpengine/ (the two copies must stay identical;
# scripts/sync-assets.sh copies kamailio/pubip.sh over rtpengine/pubip.sh).
#
# GW_PUBLIC_IP in .env can be:
#   (empty)            public door off
#   203.0.113.7        a fixed IPv4 address
#   phone.example.com  a host name (e.g. kept up to date by the ddns container) - looked up again every minute
#   auto               ask the internet "what is my IP?"                         - looked up again every minute
#
# For a host name or "auto", pub_watch runs in the background. When the answer changes (seen twice in a
# row, so one bad lookup never does anything) it stops this container; Docker starts it again
# (restart: unless-stopped) and the new start uses the new address. Phones reconnect within a minute or two.

is_ipv4() { echo "$1" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; }

lookup_public() {
  for u in ${GW_PUBIP_URLS:-https://api.ipify.org https://ipv4.icanhazip.com https://checkip.amazonaws.com}; do
    ip="$(curl -4 -fsS --max-time 5 "$u" 2>/dev/null | tr -d ' \r\n')"
    if is_ipv4 "$ip"; then echo "$ip"; return 0; fi
  done
  return 0
}

# Prints the public IPv4 to advertise, or nothing.
pub_ip_now() {
  v="${GW_PUBLIC_IP:-}"
  if [ -z "$v" ]; then return 0; fi
  if is_ipv4 "$v"; then echo "$v"; return 0; fi
  if [ "$v" = "auto" ] || [ "$v" = "AUTO" ]; then lookup_public; return 0; fi
  ip="$(getent ahostsv4 "$v" 2>/dev/null | awk 'NR==1{print $1}')"
  if is_ipv4 "$ip"; then echo "$ip"; fi
  return 0
}

# Does GW_PUBLIC_IP need watching? (host name or auto: yes; empty or fixed IP: no)
pub_needs_watch() {
  [ -n "${GW_PUBLIC_IP:-}" ] && ! is_ipv4 "$GW_PUBLIC_IP"
}

# How many child processes of $1 run the same program as $1 (Kamailio ~20 workers; rtpengine none).
pub_workers() {
  me="$(cat /proc/"$1"/comm 2>/dev/null)"; c=0
  for k in $(cat /proc/"$1"/task/*/children 2>/dev/null); do
    [ "$(cat /proc/"$k"/comm 2>/dev/null)" = "$me" ] && c=$((c + 1))
  done
  echo "$c"
}

# pub_watch <IP we started with> <PID to stop>   - run in the background with &.
# Kamailio waits for ALL its child processes before it stops (and treats one that exits early as a
# crash), so this loop exits only (a) after asking the main process to stop, or (b) when the main
# process is already stopping (its other children are gone), e.g. on "docker compose stop".
pub_watch() {
  set +e
  start="$1"; target="$2"; every="${GW_PUBLIC_IP_CHECK:-60}"; seen=""; n=0
  sleep 5; had="$(pub_workers "$target")"
  while :; do
    waited=0
    while [ "$waited" -lt "$every" ]; do
      sleep 2; waited=$((waited + 2))
      if [ "${had:-0}" -gt 0 ] && [ "$(pub_workers "$target")" -eq 0 ]; then exit 0; fi
      kill -0 "$target" 2>/dev/null || exit 0
    done
    now="$(pub_ip_now)"
    is_ipv4 "$now" || continue                 # lookup failed: never restart on a hiccup
    if [ "$now" = "$start" ]; then n=0; seen=""; continue; fi
    if [ "$now" = "$seen" ]; then n=$((n + 1)); else seen="$now"; n=1; fi
    if [ "$n" -ge 2 ]; then
      echo "public IP changed: ${start:-none} -> $now - restarting to use it" >&2
      kill -TERM "$target" 2>/dev/null
      exit 0
    fi
  done
}
