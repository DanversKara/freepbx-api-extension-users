#!/usr/bin/env python3
"""Cloudflare DDNS container against a fake Cloudflare API:   python3 tests/ddns_test.py   (must print ALL PASSED)"""
import http.server
import json
import os
import subprocess
import sys
import threading

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPT = os.path.join(HERE, "..", "docker-gateway", "ddns", "ddns.py")
TOKEN = "test-token-SECRET-123"
state = {"ip": "93.184.216.34", "records": {}, "calls": [], "next": 1}


class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def reply(self, code, obj):
        b = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(b)

    def handle_any(self, method):
        if self.path == "/ip":
            b = state["ip"].encode()
            self.send_response(200); self.end_headers(); self.wfile.write(b)
            return
        state["calls"].append((method, self.path))
        if self.headers.get("Authorization") != "Bearer " + TOKEN:
            return self.reply(403, {"success": False, "errors": [{"code": 9109, "message": "Invalid access token"}]})
        p = self.path
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length") or 0)) or b"null")
        if p.startswith("/zones?"):
            return self.reply(200, {"success": True, "result": [{"id": "Z1"}] if "name=example.com" in p and "." not in p.split("name=example.com")[1][:1] else []})
        if p.startswith("/zones/Z1/dns_records?"):
            name = p.split("name=")[1]
            res = [r for r in state["records"].values() if r["name"] == name]
            return self.reply(200, {"success": True, "result": res})
        if p == "/zones/Z1/dns_records" and method == "POST":
            rid = "R%d" % state["next"]; state["next"] += 1
            state["records"][rid] = dict(body, id=rid)
            return self.reply(200, {"success": True, "result": state["records"][rid]})
        if p.startswith("/zones/Z1/dns_records/") and method == "PATCH":
            rid = p.rsplit("/", 1)[1]
            state["records"][rid].update(body)
            return self.reply(200, {"success": True, "result": state["records"][rid]})
        return self.reply(404, {"success": False, "errors": [{"code": 7003, "message": "no route"}]})

    def do_GET(self): self.handle_any("GET")
    def do_POST(self): self.handle_any("POST")
    def do_PATCH(self): self.handle_any("PATCH")


srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), H)
threading.Thread(target=srv.serve_forever, daemon=True).start()
base = "http://127.0.0.1:%d" % srv.server_port
fails = 0


def ok(cond, name):
    global fails
    print(("ok   " if cond else "FAIL ") + name)
    fails += 0 if cond else 1


def run(**env):
    e = dict(os.environ, CF_API=base, GW_PUBIP_URLS=base + "/ip", DDNS_ONCE="1", CF_API_TOKEN=TOKEN, CF_RECORD="phone.example.com")
    e.update({k: v for k, v in env.items()})
    r = subprocess.run([sys.executable, SCRIPT], env=e, capture_output=True, text=True, timeout=30)
    return r.returncode, r.stdout + r.stderr


rc, out = run(CF_API_TOKEN="", CF_RECORD="")
ok(rc == 0 and "OFF" in out and not state["calls"], "not configured: says OFF, calls nothing")

rc, out = run()
rec = list(state["records"].values())
ok(rc == 0 and len(rec) == 1 and rec[0]["content"] == "93.184.216.34" and rec[0]["proxied"] is False, "missing record is created, DNS only")
ok(rec and rec[0]["name"] == "phone.example.com" and rec[0]["type"] == "A", "A record with the right name")

state["calls"].clear()
rc, out = run()
ok(not any(m in ("POST", "PATCH") for m, _ in state["calls"]), "same IP: nothing changed at Cloudflare")

state["ip"] = "93.184.216.35"
rc, out = run()
ok(list(state["records"].values())[0]["content"] == "93.184.216.35" and "93.184.216.34 -> 93.184.216.35" in out, "new IP: record updated")

list(state["records"].values())[0]["proxied"] = True
rc, out = run()
ok(list(state["records"].values())[0]["proxied"] is False and "DNS only" in out, "orange cloud is switched back to DNS only")

rc, out = run(CF_API_TOKEN="wrong-token")
ok(rc == 0 and "Invalid access token" in out, "bad token: clear error, no crash")
rc, out = run()
ok(TOKEN not in out, "the token is never printed")

rc, out = run(CF_RECORD="phone.other.org")
ok("no Cloudflare zone found" in out, "domain not on the account: clear error")

state["calls"].clear()
rc, out = run(CF_RECORD="phone.example.com", CF_ZONE_ID="Z1")
ok(rc == 0 and state["calls"] and not any(p.startswith("/zones?") for _, p in state["calls"]), "CF_ZONE_ID skips the zone lookup")

state["ip"] = "192.168.8.1"
before = json.dumps(state["records"])
rc, out = run()
ok(json.dumps(state["records"]) == before and "could not find the public IP" in out, "a private IP answer is never published")

rc, out = run(CF_RECORD="phone.example.com, vpn.example.com")
state["ip"] = "93.184.216.36"
rc, out = run(CF_RECORD="phone.example.com,vpn.example.com")
names = sorted(r["name"] for r in state["records"].values() if r["content"] == "93.184.216.36")
ok(names == ["phone.example.com", "vpn.example.com"], "several names in CF_RECORD all follow")

srv.shutdown()
print("\n%d FAILED" % fails if fails else "\nALL PASSED")
sys.exit(1 if fails else 0)
