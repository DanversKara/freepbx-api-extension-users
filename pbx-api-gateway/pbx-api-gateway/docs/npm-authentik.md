# NPM + Authentik in front of the remote panel

The panel is only reachable from NPM (192.168.8.147); the gateway host's firewall (e.g. Proxmox)
enforces that. NPM sends every visitor to Authentik first, then passes the
signed-in username to the panel in the `X-authentik-username` header.

## 1. Authentik

1. **Applications → Providers → Create → Proxy Provider**
   - Name: `pbx-admin`
   - Authorization flow: the default (explicit consent is fine)
   - Mode: **Forward auth (single application)**
   - External host: `https://pbx-admin.yourdomain.com`
2. **Applications → Applications → Create**
   - Name: `PBX Admin`, slug `pbx-admin`, Provider: `pbx-admin`
   - Optional but recommended: **Policy / Group / User Bindings**, bound to just your user.
3. **Applications → Outposts → authentik Embedded Outpost → Edit**: move `PBX Admin` into
   **Selected Applications** and click Update. Use the *existing* Embedded Outpost. Do **not** click
   "New Outpost"; a second, undeployed outpost claiming the same providers just confuses things.
4. Turn on 2FA (TOTP or passkey) for your user if you haven't already.

## 2. DNS

Create an A record for `pbx-admin.yourdomain.com` pointing at your home IP, the same way `mail.` is set up.

## 3. NPM proxy host

**Details**
- Domain: `pbx-admin.yourdomain.com`
- Scheme `http`, Forward Hostname `192.168.8.100`, Port `8000`
- ✅ Block Common Exploits · Websockets: off (not needed)

**SSL**: request a new Let's Encrypt cert · ✅ Force SSL · ✅ HTTP/2 · ✅ HSTS

**Advanced**: paste the block below. Replace `AUTHENTIK_IP` with your Authentik server's IP and
`pbx-admin.yourdomain.com` (2 places) with your panel's domain. The domain is hard-coded with
`https` on purpose; see Troubleshooting.
(NPM sees the custom `location /` and drops its own default location.)

```nginx
proxy_buffers 8 16k;
proxy_buffer_size 32k;
port_in_redirect off;

location / {
    proxy_pass          $forward_scheme://$server:$port;
    auth_request        /outpost.goauthentik.io/auth/nginx;
    error_page          401 = @goauthentik_proxy_signin;
    auth_request_set    $auth_cookie $upstream_http_set_cookie;
    add_header          Set-Cookie $auth_cookie;

    # The panel trusts ONLY this header, and only from NPM.
    # proxy_set_header REPLACES anything a visitor tries to send themselves.
    auth_request_set    $authentik_username $upstream_http_x_authentik_username;
    proxy_set_header    X-authentik-username $authentik_username;
}

location /outpost.goauthentik.io {
    proxy_pass              http://AUTHENTIK_IP:9000/outpost.goauthentik.io;
    proxy_set_header        Host $host;
    proxy_set_header        X-Original-URL https://pbx-admin.yourdomain.com$request_uri;
    add_header              Set-Cookie $auth_cookie;
    auth_request_set        $auth_cookie $upstream_http_set_cookie;
    proxy_pass_request_body off;
    proxy_set_header        Content-Length "";
}

location @goauthentik_proxy_signin {
    internal;
    add_header Set-Cookie $auth_cookie;
    return 302 /outpost.goauthentik.io/start?rd=https://pbx-admin.yourdomain.com$request_uri;
}
```

## 4. Test

- Open `https://pbx-admin.yourdomain.com`. You should get the Authentik login, then the panel.
  The top right of the panel shows "signed in as <you>".
- From any LAN machine other than NPM, run `curl -m 5 http://192.168.8.100:8000`. It should time out (Proxmox firewall).
- To restrict the panel to specific Authentik usernames, set `PANEL_ALLOWED_USERS=yourname` in `docker-gateway/.env`.

## Troubleshooting (all seen in the field)

**Check whether Authentik knows the app.** Run this from the NPM host; it should print `401`:
```bash
curl -s -o /dev/null -w "%{http_code}\n" -H "Host: pbx-admin.yourdomain.com" \
  -H "X-Forwarded-Host: pbx-admin.yourdomain.com" -H "X-Original-URL: https://pbx-admin.yourdomain.com/" \
  http://AUTHENTIK_IP:9000/outpost.goauthentik.io/auth/nginx
```
To check the test itself, run it against another app you already protect with Authentik; that one should also print `401`.

| What you see | Cause | Fix |
|---|---|---|
| NPM's "Congratulations!" page | No working proxy host for that domain (never created, disabled, or the config was rejected) | Create or enable the host. In the NPM container, `grep -l <domain> /data/nginx/proxy_host/*.conf` must find a file |
| **500** from openresty | Authentik answered **404** to the auth check: the app isn't on the Embedded Outpost, or External host ≠ domain | Add the app to the Embedded Outpost, make External host match exactly, wait 30 s or restart Authentik |
| **403** on `/outpost.goauthentik.io/start?rd=http://…` | NPM **Block Common Exploits** blocks `=http://` in URLs | Use the hard-coded `https://` snippet above + **Force SSL** (or untick Block Common Exploits for this host) |
| Panel says **401 Not signed in through Authentik** | Reached the panel without the Authentik header | The Advanced snippet isn't active on this host |
| Panel says **No ED25519 host key is known** | Old panel image: known_hosts not readable by the non-root web user | Update `panel/entrypoint.sh` from this repo, then `docker compose up -d --build panel` |
| `curl` test says 401 but the browser loops | Cookie/domain mismatch | External host must be exactly `https://<domain>` (no port, no trailing slash) |

## What the remote panel can and can't do (safety lock)

| Can | Can't (PBX page over VPN/LAN only) |
|---|---|
| Add users (internal-only), edit name/extensions/limits | Turn External, 911, or International **on** |
| Turn External/911/International **off** | Release the kill switch |
| New password (shown once), delete user | Show an existing password |
| Engage the kill switch, view calls and the audit log | Change settings or the remote token |

---

# User portal (optional): each person sees only their own account

The portal is a second small web app on the gateway host (**port 8010**). It shows a signed-in person
their own line only: who they can call, their signed-in phones and calls in progress, call history,
sign-ins, failed attempts, and a **Get a new password** button. It can't see anyone else and can't
change anything else, because on the PBX its own SSH key only runs `apiusers-remote --portal`.

> ⚠️ **Keep the admin panel and the portal as two separate Authentik applications.** Bind the
> **admin** app (`PBX Admin`) to **you only**, and bind the **portal** app to the people who have a
> phone account. Otherwise a family member who can sign in to the portal could also open the admin panel.

## 1. Gateway host (Docker CT)

```bash
cd /opt/pbx-api-gateway && bash scripts/setup-docker.sh     # makes ssh-portal/ and the portal part of .env
```
It prints an `--add-portal-key` command. Run that **on the PBX** (next step), then here:
```bash
nano docker-gateway/.env      # PORTAL_TOKEN = the portal token from the PBX page (Settings > User portal)
cd docker-gateway && docker compose up -d --build portal
```

## 2. PBX

```bash
bash scripts/install-pbx.sh --add-portal-key "ssh-ed25519 AAAA... portal@pbx-api-gateway"
```
Then on the PBX page: **Settings → User portal → Turn the user portal on → Save**, and on each of a
person's phone accounts fill in **User portal login** with their **Authentik user name** (exactly what
Authentik shows as the username; it's not case-sensitive). Someone with several phones gets the **same**
login on each of them. Creating the Authentik users, in plain steps: **[family-setup.md](family-setup.md)**.

## 3. Authentik

Same as for the admin panel (section 1 at the top), with different names:
- Proxy Provider `pbx-portal`, **Forward auth (single application)**, External host `https://phone.yourdomain.com`
- Application `Phone portal`, slug `pbx-portal`. **Bindings:** a group (e.g. `phone-users`) with the
  people who have a phone account. Add each person as an Authentik user (email + password; 2FA recommended).
- Add `Phone portal` to the **Embedded Outpost**.

## 4. NPM

New proxy host `phone.yourdomain.com` → `http://192.168.8.100:8010`, SSL like the admin panel,
and the **same Advanced block** as above with `phone.yourdomain.com` in the 2 places.

Firewall: the portal port must only be reachable from NPM. `scripts/proxmox-firewall.sh` now
allows **8010/tcp only from NPM** (re-run it on the Proxmox host).

## Test

Open `https://phone.yourdomain.com`, sign in as a family member: you should see **their** name and
reach number. Someone with no linked account sees *"No phone account is linked to …"*.
Every **Get a new password** shows up in the Audit log as `portal:<user>` and in the alert e-mail.
