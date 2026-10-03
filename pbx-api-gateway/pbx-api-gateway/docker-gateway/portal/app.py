"""
API Users – USER PORTAL (Docker, 192.168.8.100:8010, behind NPM + Authentik).

Each person signs in through Authentik and sees ONLY their own phone accounts (one login can own several:
one account per phone): who they can call, signed-in phones, calls in progress, call history, sign-ins,
failed attempts, and a button for a new Zoiper password per phone. Nothing about other users, no admin actions.

Who is this?  NPM's Authentik forward-auth sets X-authentik-username (PORTAL_AUTH_HEADER). We only
trust it when the request comes from NPM (PORTAL_TRUSTED_PROXY). The PBX maps that login to ONE
or more accounts (the "User portal login" on each account). For a new password the portal names the phone,
and the PBX refuses any account that isn't linked to this login.

How it reaches the PBX: ssh apiportal@PBX with its OWN key. On the PBX that key can only run
"apiusers-remote --portal" (ops: me, rotate). Even a stolen portal key can't administer anything.
Stateless: stores nothing.
"""
import json
import os
import secrets
import subprocess

from flask import Flask, abort, jsonify, redirect, render_template, request, session

app = Flask(__name__)
app.secret_key = os.environ.get("PORTAL_SECRET_KEY") or secrets.token_hex(32)
app.config.update(SESSION_COOKIE_HTTPONLY=True, SESSION_COOKIE_SAMESITE="Strict",
                  SESSION_COOKIE_SECURE=os.environ.get("PORTAL_COOKIE_SECURE", "1") == "1",
                  SESSION_COOKIE_NAME="apiu_portal")

PBX_HOST = os.environ.get("PBX_SSH_HOST", "192.168.8.220")
PBX_USER = os.environ.get("PORTAL_SSH_USER", "apiportal")
TOKEN = os.environ.get("PORTAL_TOKEN", "")
SSH_KEY = "/run/portal/id_ed25519"
KNOWN_HOSTS = "/run/portal/known_hosts"
TRUSTED_PROXY = os.environ.get("PORTAL_TRUSTED_PROXY", "192.168.8.147")
AUTH_HEADER = os.environ.get("PORTAL_AUTH_HEADER", "X-authentik-username")
SITE = os.environ.get("PORTAL_SITE_NAME", "Home phone")


def me():
    return (request.headers.get(AUTH_HEADER, "") or "").strip().lower()


def pbx(op, account_id=""):
    req = {"token": TOKEN, "op": op, "login": me(), "id": account_id}
    cmd = ["ssh", "-i", SSH_KEY, "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes",
           "-o", f"UserKnownHostsFile={KNOWN_HOSTS}", "-o", "ConnectTimeout=5", "-o", "LogLevel=ERROR",
           f"{PBX_USER}@{PBX_HOST}"]
    try:
        p = subprocess.run(cmd, input=json.dumps(req).encode(), capture_output=True, timeout=25)
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": "The phone system did not answer. Try again in a minute."}
    out = p.stdout.decode(errors="replace").strip().splitlines()
    if p.returncode != 0 or not out:
        return {"ok": False, "error": "The phone system can't be reached right now."}
    try:
        return json.loads(out[-1])
    except ValueError:
        return {"ok": False, "error": "Unexpected answer from the phone system."}


@app.before_request
def gate():
    if request.path == "/healthz":
        return None
    if TRUSTED_PROXY and request.remote_addr != TRUSTED_PROXY:
        abort(403, "Only reachable through the sign-in page")
    if not me():
        abort(401, "Please sign in")
    if "csrf" not in session:
        session["csrf"] = secrets.token_hex(16)
    if request.method == "POST" and not secrets.compare_digest(session.get("csrf", ""), request.form.get("csrf", "")):
        abort(400, "This page expired. Reload it and try again.")
    return None


@app.get("/healthz")
def healthz():
    return "ok"


@app.get("/")
def index():
    d = pbx("me")
    return render_template("portal.html", d=d, creds=None, csrf=session["csrf"], site=SITE, login=me())


@app.get("/me.json")
def me_json():
    d = pbx("me")
    accounts = [{"id": a.get("id"), "devices": a.get("devices", []), "live_calls": a.get("live_calls", [])}
                for a in d.get("accounts", [])] if d.get("ok") else []
    resp = jsonify({"ok": d.get("ok"), "error": d.get("error"), "now": d.get("now"), "accounts": accounts})
    resp.headers["Cache-Control"] = "no-store"
    return resp


@app.post("/new-password")
def new_password():
    # The PBX checks the account really belongs to this login.
    r = pbx("rotate", request.form.get("id", "")[:20])
    if not r.get("ok"):
        d = pbx("me")
        d["error"] = d.get("error") or r.get("error")
        return render_template("portal.html", d=d, creds=None, csrf=session["csrf"], site=SITE, login=me(),
                               err=r.get("error"))
    d = pbx("me")
    return render_template("portal.html", d=d, creds=r, csrf=session["csrf"], site=SITE, login=me())


@app.errorhandler(405)
def wrong_method(e):
    return redirect("/")


@app.errorhandler(400)
@app.errorhandler(401)
@app.errorhandler(403)
def denied(e):
    return f"<h3>{e.code}</h3><p>{e.description}</p>", e.code
