"""
API Users – remote admin panel (Docker, 192.168.8.100:8000, behind NPM + Authentik).

STATELESS: every action is forwarded to the PBX module over SSH:
    ssh apiremote@192.168.8.220   (forced command -> /usr/local/sbin/apiusers-remote)
The PBX enforces the safety lock; this UI only mirrors it (locked checkboxes).

Who is the user?  NPM's Authentik forward-auth sets X-authentik-username.
We only trust that header when the request comes from NPM (PANEL_TRUSTED_PROXY).
Your host firewall should also only let the reverse proxy reach port 8000.

ZOIPER-PRO-TODO: when public TLS exists, show "Server (public TLS)" from the
PBX's client_tls_addr setting in the credentials box (templates/index.html).
"""
import json
import os
import secrets
import subprocess

from flask import Flask, abort, has_request_context, jsonify, redirect, render_template, request, session

app = Flask(__name__)
app.secret_key = os.environ.get("PANEL_SECRET_KEY") or secrets.token_hex(32)
app.config.update(SESSION_COOKIE_HTTPONLY=True, SESSION_COOKIE_SAMESITE="Strict",
                  SESSION_COOKIE_SECURE=os.environ.get("PANEL_COOKIE_SECURE", "1") == "1")

PBX_HOST = os.environ.get("PBX_SSH_HOST", "192.168.8.220")
PBX_USER = os.environ.get("PBX_SSH_USER", "apiremote")
PBX_TOKEN = os.environ.get("PBX_REMOTE_TOKEN", "")
SSH_KEY = os.environ.get("PBX_SSH_KEY", "/run/panel/id_ed25519")
KNOWN_HOSTS = os.environ.get("PBX_KNOWN_HOSTS", "/run/panel/known_hosts")
TRUSTED_PROXY = os.environ.get("PANEL_TRUSTED_PROXY", "192.168.8.147")
AUTH_HEADER = os.environ.get("PANEL_AUTH_HEADER", "X-authentik-username")
ALLOWED_USERS = {u.strip() for u in os.environ.get("PANEL_ALLOWED_USERS", "").split(",") if u.strip()}


# ----------------------------------------------------------------- helpers
def pbx(op, args=None):
    """Run one op on the PBX. Returns dict with ok/error."""
    req = {"token": PBX_TOKEN, "op": op, "args": args or {}, "actor": current_user()}
    cmd = ["ssh", "-i", SSH_KEY, "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes",
           "-o", f"UserKnownHostsFile={KNOWN_HOSTS}", "-o", "ConnectTimeout=5",
           "-o", "LogLevel=ERROR", f"{PBX_USER}@{PBX_HOST}"]
    try:
        p = subprocess.run(cmd, input=json.dumps(req).encode(), capture_output=True, timeout=25)
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": "PBX did not answer (timeout)"}
    out = p.stdout.decode(errors="replace").strip().splitlines()
    if p.returncode != 0 or not out:
        err = p.stderr.decode(errors="replace").strip()[-300:]
        return {"ok": False, "error": f"cannot reach PBX over SSH ({p.returncode}): {err or 'no output'}"}
    try:
        return json.loads(out[-1])
    except ValueError:
        return {"ok": False, "error": "PBX returned something that is not JSON"}


def current_user():
    return request.headers.get(AUTH_HEADER, "") if has_request_context() else "cli"


@app.before_request
def gate():
    if request.path == "/healthz":
        return None
    if TRUSTED_PROXY and request.remote_addr != TRUSTED_PROXY:
        abort(403, "Only reachable through Nginx Proxy Manager")
    user = current_user()
    if not user:
        abort(401, "Not signed in through Authentik")
    if ALLOWED_USERS and user not in ALLOWED_USERS:
        abort(403, "Your Authentik user is not allowed here")
    if "csrf" not in session:
        session["csrf"] = secrets.token_hex(16)
    if request.method == "POST":
        if not secrets.compare_digest(session.get("csrf", ""), request.form.get("csrf", "")):
            abort(400, "Form expired - reload the page")
    return None


def user_args(form):
    args = {
        "name": form.get("name", ""),
        "reach": form.get("reach", ""),
        "enabled": "enabled" in form,
        "internal": "internal" in form,
        "allowed": form.getlist("allowed"),
        "max_calls": form.get("max_calls", 1),
        "max_minutes": form.get("max_minutes", 120),
    }
    # Only send lists the form actually showed, so a missing section never wipes them.
    if form.get("confs_sent") == "1":
        args["confs"] = form.getlist("confs")
    return args


def page(msg=None, err=None, creds=None, calls=None, edit_id=None):
    data = pbx("list")
    audit = pbx("audit") if data.get("ok") else {"audit": []}
    if not data.get("ok"):
        err = err or data.get("error")
        data = {"users": [], "kill_switch": False, "safety_lock": True, "local_extensions": []}
    users = data["users"]
    edit = next((u for u in users if u["id"] == edit_id), None)
    return render_template("index.html", d=data, users=users, edit=edit, msg=msg, err=err,
                           creds=creds, calls=calls, audit=list(reversed(audit.get("audit", [])))[:40],
                           me=current_user(), csrf=session["csrf"])


# ------------------------------------------------------------------ routes
@app.get("/healthz")
def healthz():
    return "ok"


@app.get("/")
def index():
    return page(edit_id=request.args.get("edit"))


@app.post("/create")
def create():
    # Risky flags are never sent from here; the PBX would refuse them anyway.
    r = pbx("create", user_args(request.form))
    if r.get("ok"):
        return page(msg="User created. Copy the password now - it won't be shown again here.", creds=r)
    return page(err=r.get("error"))


@app.post("/update/<uid>")
def update(uid):
    args = user_args(request.form)
    args["id"] = uid
    # Feature codes: the panel can only REMOVE them (untick); adding is PBX-page only (safety lock).
    if request.form.get("features_sent") == "1":
        args["features"] = request.form.getlist("features")
    # Turning risky flags OFF is allowed remotely; ON is not (PBX enforces).
    for k in ("external", "e911", "international", "disa"):
        if request.form.get(k + "_off") == "1":
            args[k] = False
    r = pbx("update", args)
    return page(msg="Saved." if r.get("ok") else None, err=None if r.get("ok") else r.get("error"),
                edit_id=None if r.get("ok") else uid)


@app.post("/rotate/<uid>")
def rotate(uid):
    r = pbx("rotate", {"id": uid})
    if r.get("ok"):
        return page(msg="New password generated - update Zoiper.", creds=r)
    return page(err=r.get("error"))


@app.post("/delete/<uid>")
def delete(uid):
    r = pbx("delete", {"id": uid})
    return page(msg="Deleted." if r.get("ok") else None, err=None if r.get("ok") else r.get("error"))


@app.post("/kill")
def kill():
    r = pbx("kill")
    return page(msg="KILL SWITCH ENGAGED. Release it from the PBX page over VPN." if r.get("ok") else None,
                err=None if r.get("ok") else r.get("error"))


@app.post("/calls/<uid>")
def calls(uid):
    r = pbx("calls", {"id": uid})
    if r.get("ok"):
        return page(calls={"id": uid, "rows": r.get("calls", [])})
    return page(err=r.get("error"))


@app.get("/live.json")
def live():
    # Live view data (signed-in phones, calls, sign-in history, failed sign-ins).
    # Polled by static/live-view.js every few seconds while the tab is visible.
    r = pbx("live")
    resp = jsonify(r if isinstance(r, dict) else {"ok": False, "error": "bad answer from PBX"})
    resp.headers["Cache-Control"] = "no-store"
    return resp


@app.post("/hangup")
def hangup():
    # Ending a call is allowed remotely (it only stops things). The PBX checks the
    # channel is a live API-user call and writes it to the audit log.
    r = pbx("hangup", {"channel": request.form.get("channel", "")[:120]})
    return page(msg="Call ended." if r.get("ok") else None, err=None if r.get("ok") else r.get("error"))


@app.errorhandler(405)
def wrong_method(e):
    # e.g. reload/bookmark of a POST-only URL like /calls/<id>: go back to the main page
    return redirect("/")


@app.errorhandler(400)
@app.errorhandler(401)
@app.errorhandler(403)
def denied(e):
    return f"<h3>{e.code}</h3><p>{e.description}</p>", e.code
