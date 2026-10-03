#!/usr/bin/env python3
"""
Optional Cloudflare dynamic DNS for the public door (no tunnel needed, none would carry SIP anyway).

Every few minutes: "what is my public IP?" -> if the Cloudflare A record(s) don't match, fix them.
The record is always kept DNS-only (grey cloud): Cloudflare's orange-cloud proxy only carries web
traffic, so phone calls would break through it.

.env:
  CF_API_TOKEN   Cloudflare API token, template "Edit zone DNS", limited to your one domain (required)
  CF_RECORD      the name(s) phones use, e.g. phone.example.com  (comma-separated for several)
  CF_ZONE_ID     optional: the domain's Zone ID (Cloudflare > your domain > Overview, right side);
                 only needed if the log says it can't find the zone
  DDNS_INTERVAL  seconds between checks (default 300, minimum 60)
  CF_TTL         record TTL in seconds (default 60; 1 = Cloudflare "Auto")

Nothing set -> the container just waits and does nothing. The token is never printed.
Standard library only.
"""
import ipaddress
import json
import os
import signal
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

API = os.environ.get("CF_API", "https://api.cloudflare.com/client/v4").rstrip("/")
TOKEN = os.environ.get("CF_API_TOKEN", "").strip()
NAMES = [n.strip().lower().rstrip(".") for n in os.environ.get("CF_RECORD", "").split(",") if n.strip()]
ZONE_ID = os.environ.get("CF_ZONE_ID", "").strip()
INTERVAL = max(int(os.environ.get("DDNS_INTERVAL", "300") or 300), int(os.environ.get("DDNS_MIN_INTERVAL", "60")))
TTL = int(os.environ.get("CF_TTL", "60") or 60)
URLS = (os.environ.get("GW_PUBIP_URLS") or
        "https://api.ipify.org https://ipv4.icanhazip.com https://checkip.amazonaws.com").split()
RECHECK = int(os.environ.get("DDNS_RECHECK", "3600"))   # look at Cloudflare at least this often even if the IP is the same
ONCE = os.environ.get("DDNS_ONCE") == "1"                # tests: one pass, then exit


def log(msg):
    print(time.strftime("%Y-%m-%d %H:%M:%S ") + msg, flush=True)


def stop(*_):
    sys.exit(0)


def public_ip():
    for u in URLS:
        try:
            with urllib.request.urlopen(urllib.request.Request(u, headers={"User-Agent": "pbx-api-gateway-ddns"}), timeout=8) as r:
                ip = r.read(64).decode("ascii", "ignore").strip()
            a = ipaddress.ip_address(ip)
            if a.version == 4 and a.is_global:
                return ip
        except Exception:
            continue
    return None


class CFError(Exception):
    pass


def cf(method, path, body=None):
    req = urllib.request.Request(API + path, method=method,
                                 data=json.dumps(body).encode() if body is not None else None,
                                 headers={"Authorization": "Bearer " + TOKEN, "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            d = json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        try:
            d = json.loads(e.read().decode())
        except Exception:
            raise CFError(f"HTTP {e.code}")
    except Exception as e:
        raise CFError(type(e).__name__)
    if not d.get("success"):
        errs = "; ".join(f"{x.get('code')}: {x.get('message')}" for x in d.get("errors", [])) or "unknown error"
        raise CFError(errs)
    return d.get("result")


_zones = {}


def zone_for(name):
    if ZONE_ID:
        return ZONE_ID
    if name in _zones:
        return _zones[name]
    labels = name.split(".")
    for i in range(len(labels) - 1):            # phone.home.example.com -> home.example.com -> example.com
        cand = ".".join(labels[i:])
        res = cf("GET", "/zones?" + urllib.parse.urlencode({"name": cand}))
        if res:
            _zones[name] = res[0]["id"]
            return _zones[name]
    raise CFError(f"no Cloudflare zone found for {name} - is the domain on this Cloudflare account and the token "
                  "allowed on it? Or set CF_ZONE_ID in .env")


def sync(name, ip):
    """Make the A record `name` point at `ip`, DNS only. Returns a short description of what happened."""
    zid = zone_for(name)
    recs = cf("GET", f"/zones/{zid}/dns_records?" + urllib.parse.urlencode({"type": "A", "name": name})) or []
    body = {"type": "A", "name": name, "content": ip, "ttl": TTL, "proxied": False,
            "comment": "pbx-api-gateway ddns (keep DNS only)"}
    if not recs:
        cf("POST", f"/zones/{zid}/dns_records", body)
        return f"created {name} -> {ip}"
    r = recs[0]
    if r.get("content") == ip and not r.get("proxied"):
        return None
    was = r.get("content")
    if r.get("proxied"):
        log(f"NOTE: {name} was orange-cloud (proxied); calls can't go through that - setting it to DNS only")
    cf("PATCH", f"/zones/{zid}/dns_records/{r['id']}", body)
    return f"updated {name}: {was} -> {ip}"


def main():
    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    if not TOKEN or not NAMES:
        log("Cloudflare DDNS is OFF (set CF_API_TOKEN and CF_RECORD in .env to use it). Nothing to do.")
        if ONCE:
            return 0
        while True:
            time.sleep(86400)
    log(f"Cloudflare DDNS on for {', '.join(NAMES)}: checking every {INTERVAL}s, records kept DNS only (grey cloud)")
    last_ip, last_ok = None, 0.0
    while True:
        ip = public_ip()
        if not ip:
            log("could not find the public IP right now (internet down?) - trying again later")
        elif ip != last_ip or time.time() - last_ok >= RECHECK:
            if last_ip and ip != last_ip:
                log(f"public IP changed: {last_ip} -> {ip}")
            ok = True
            for n in NAMES:
                try:
                    what = sync(n, ip)
                    if what:
                        log(what)
                except CFError as e:
                    ok = False
                    log(f"Cloudflare error for {n}: {e}")
            if ok:
                if last_ip is None:
                    log(f"{', '.join(NAMES)} -> {ip} (OK)")
                last_ip, last_ok = ip, time.time()
        if ONCE:
            return 0
        time.sleep(INTERVAL)


if __name__ == "__main__":
    sys.exit(main())
