# CLAUDE.md – read this first

Notes for Claude (or anyone) picking this project up later. Everything here was
true when the project was built (Oct 2026). Search the tree for
`ZOIPER-PRO-TODO` to find every spot that changes for the public TLS phase.

## What this is

"API Users" for an Incredible PBX / FreePBX 17 system: locked-down SIP accounts
(Zoiper etc.) that are **not** real extensions, never expose the PBX, and carry
per-user permissions:

- internal calls, only to whitelisted extensions
- external (US/Canada)
- 911/E911
- international

**Home-lab specifics** (IPs, VM/CT numbers, MACs, live status) are in
`CLAUDE.local.md`, which is private and git-ignored. Read it too if it exists.
The example addresses below are the defaults in `.env.example`:

| Role | Example |
|---|---|
| PBX | 192.168.8.220 |
| Gateway host (Docker) | 192.168.8.100 |
| Reverse proxy (NPM) | 192.168.8.147 |

## Architecture

```
Zoiper (AstroWarp) --UDP--> <AstroWarp VIP>:5070 --> Kamailio "vpn" socket  ┐
Zoiper (home Wi-Fi) -UDP--> 192.168.8.100:5072  --> Kamailio "lan" socket  ├─ gateway host
                                                     Kamailio "pbx" :5071  ┘
                                                          │ UDP, LAN only
                                                          ▼
                                     PBX 192.168.8.220:5099 transport-apiusers
                                     endpoint apiu-xxxx (permit 192.168.8.100 only)
                                     context apiusers-<id>  (the permissions)

Audio: phone <-> rtpengine (gateway host, UDP 40000-40100) <-> PBX RTP 10000-20000

Admin:
  PBX page  : http://192.168.8.220/admin -> Applications -> API Users   (source=local, full rights)
  Remote    : https://<npm host> -> NPM -> Authentik -> panel 192.168.8.100:8000
              panel --ssh apiremote@PBX (forced command)--> apiusers-remote  (source=remote, SAFETY LOCK)
```

**How a VPN connects** (see README → "Connect your VPN"):

- **NAT-style (AstroWarp)**: the client reaches a *virtual IP* that is DNATed to the gateway.
  It uses socket `vpn` :5070, and `GW_VPN_ADVERTISE_IP` = that virtual IP, so SIP and SDP carry it.
- **Routed (Tailscale subnet router, WireGuard routed to the LAN)**: the client reaches the
  real LAN IP. It uses socket `lan` :5072, and nothing extra is needed.
- **Tailscale/WireGuard running *on* the gateway host itself** (traffic to 100.x / 10.x on
  another interface) is NOT supported yet. Kamailio only listens on GW_LAN_IP. Adding it would
  need another `listen=` plus an rtpengine interface, like the "pub" plan below.

**Single source of truth:** the FreePBX module (table `apiusers_kv`, one JSON
row `state`). Kamailio has no user database, and the panel stores nothing.

## File map

| Path | Purpose |
|---|---|
| `pbx-module/apiusers/lib/Engine.php` | All rules: create/update/delete/rotate/kill, validation, **safety lock** (`remoteGuard`), audit. Pure PHP. |
| `pbx-module/apiusers/lib/ConfigGen.php` | Generates `pjsip_apiusers.conf` + `extensions_apiusers.conf`. Pure PHP. |
| `pbx-module/apiusers/Apiusers.class.php` | FreePBX BMO glue: DB, write files, AMI reload, hang up, CDR, `remoteCall()` |
| `pbx-module/apiusers/lib/Live.php` | Live view parsers (pure PHP): `devices()` from `database show registrar` + `pjsip show contacts`, `calls()` from `core show channels concise` + `group show channels`, `failures()` from the `full` log tail, `presence()` diff, `disaLocks()` |
| `pbx-module/apiusers/assets/live-view.js` | Live view UI (both pages; source copy, synced like share-card.js). DOM built with textContent only |
| `pbx-module/apiusers/bin/apiusers-presence` | cron (`/etc/cron.d/apiusers`, every minute, as asterisk): `presenceTick()` → kv rows `presence` + `signins` |
| `pbx-module/apiusers/lib/Alerts.php`, `lib/Mailer.php` | Alert rules (what is worth an e-mail, de-dup, digest) and a dependency-free SMTP client |
| `docker-gateway/portal/` | Optional user portal (Flask, own SSH key → `apiusers-remote --portal`) |
| `docker-gateway/kamailio/pubip.sh` (+ copy in `rtpengine/`) | GW_PUBLIC_IP = IP / name / auto, and the watcher that restarts on an IP change |
| `docker-gateway/ddns/` | Optional Cloudflare dynamic DNS (grey cloud only) |
| `pbx-module/apiusers/lib/PublicIp.php` | PBX side: current public IP, change alerts, "Use new IP" banner |
| `pbx-module/apiusers/views/page.php` | PBX admin page |
| `pbx-module/apiusers/bin/apiusers-remote` | SSH forced command (JSON in / JSON out) |
| `pbx-module/apiusers/assets/share-card.js` | Share card (QR, PNG, email, copy, print), all client-side. **Source copy**; `scripts/sync-assets.sh` copies it + `qrcode.js` to `docker-gateway/panel/static/`. The PBX page inlines it (no FreePBX asset routing needed); the panel serves `/static/` |
| `pbx-module/apiusers/assets/qrcode.js` | Vendored qrcode-generator 2.0.4 (MIT) + UTF-8 shim, UMD footer removed (avoids AMD loader clashes) |
| `docker-gateway/kamailio/kamailio.cfg.tpl` | SIP front door (envsubst `GW_*`) |
| `docker-gateway/rtpengine/entrypoint.sh` | Audio relay, interfaces `lan` + `vpn` |
| `docker-gateway/panel/app.py` + `templates/index.html` | Remote panel (Flask, stateless) |
| `scripts/install-pbx.sh` | Run on PBX. `--add-key` installs the panel's SSH key |
| `scripts/update-pbx.sh` | Run on PBX to update the module in place + regenerate config from saved users |
| `scripts/setup-docker.sh` | Run on the gateway host |
| `scripts/proxmox-firewall.sh` | Optional, Proxmox host: rewrites `/etc/pve/firewall/<CT>.fw` (env CT/NPM_IP/PBX_IP) |
| `docs/npm-authentik.md` | NPM proxy host + Authentik forward-auth |
| `tests/engine_test.php`, `tests/live_test.php`, `tests/features_test.php` | all (plus `tests/alerts_test.php`) must print ALL PASSED. `tests/fixtures/live/` = real Asterisk 20 output |

## Security invariants. Do not break these.

1. **API endpoints only accept traffic from the gateway**: `deny=0.0.0.0/0.0.0.0`
   plus `permit=<gateway_ip>/255.255.255.255`, generated per endpoint.
2. **Permissions live in the dialplan context.** Anything not explicitly
   listed goes to the catch-all and is denied. More-specific patterns win in
   Asterisk, which is how premium and Caribbean numbers beat `_NXXNXXXXXX`.
3. **No transfers**, blocked three ways:
   - `allow_transfer=no`
   - `DIAL_OPTIONS=tr` and `TRUNK_OPTIONS=I` on the caller channel, so there is no `T`. FreePBX defaults include `T`, which would allow DTMF `##` transfer in from-internal.
   - `TRANSFER_CONTEXT=apiusers-notransfer`

   Internal calls go to `ext-local`, **not** `from-internal`.
4. **Safety lock** (Engine::remoteGuard): remote callers can't turn
   external/911/international ON, can't release the kill switch, can't change
   settings, and can't reveal secrets. They *can* turn things off and engage the kill switch.
5. **Kamailio never relays for clients**:
   - New requests from phones are always sent to the PBX.
   - In-dialog requests from phones must route to the PBX IP (anti-relay check).
   - The `pbx` socket drops anything not from the PBX IP.
6. **Account type is immutable** (`public` set at create, never changed; Engine::applyFields). Public
   accounts: external/e911/international are refused even for the admin and forced false. Their ONLY way out is
   DISA (`[apiusers-disa]` + `[apiusers-disa-out]`): PIN = sha1(salt.pin) compared in dialplan, 3 tries/call,
   5 fails → AstDB lock 1 h, NANP only, 911/933/112/N11/011/Caribbean/premium always denied.
7. **Door enforcement:** Kamailio strips any X-Gw-Net from phones and stamps vpn|lan|pub. `[apiusers-netcheck]`
   (called from `apiusers-pre` and the 911 lines) refuses `pub` unless the user is a Public account.
   The pub socket only exists with `-A WITH_PUB` (entrypoint sets it when GW_PUBLIC_IP resolves).
8. **911 warning:** a remote user's 911 call goes out with the PBX's E911 address
   (the owner's house). The UI warns about this. Keep 911 off for remote people.
9. **Only `apiu-…` usernames pass the gateway** (FROM_CLIENT: `$fU`, `$au`, and `$tU` for REGISTER must match
   `^apiu-[0-9a-f]+$`, else 403 + log `blocked non-API username`). Asterisk picks the endpoint by From user or auth
   username and real extensions have no ACL, so without this a real extension (701…) could be registered through the
   gateway / public door. Found Oct 2026 while building the Live view; proven in the sandbox (worked before, 403 after).
10. **Live view is read-only** except `hangup` (Engine op, allowed remotely, channel must match `PJSIP/apiu-…-…`, be
   live, and belong to a known user; audited) and `disa_unlock` (Engine op, PBX page only, Public accounts only).
11. **Conference rooms + feature codes** (user fields `confs`, `features`; Engine::applyFields / checkFeatureCode,
   ConfigGen 1b/1c). Rooms go ONLY to `Goto(ext-meetme,ROOM,1)`; validated against FreePBX table `meetme` when readable.
   Feature codes are exact extens → `Goto(from-internal,CODE,1)` after `apiusers-pre`: `[0-9*#]{2,10}`, digits-only
   max 4 digits, never starting 0/1/9, never 911/933/112/N11. Remote (safety lock) may remove but never add codes.
   Public accounts: features refused even locally, forced [] and skipped by ConfigGen.
   Directory comes from FreePBX tables `meetme` and `featurecodes` (Apiusers::directory(); null = unreadable).
12. **User portal** (`docker-gateway/portal`, `Apiusers::portalCall`): own SSH user `apiportal`, own key (`ssh-portal/`),
   forced command + sudoers allow ONLY `apiusers-remote --portal`; own token `portal_token`; off unless `portal_enabled`.
   The portal sends the Authentik login; the PBX maps it to EVERY account whose `portal_login` matches (lower-case; one
   person = one login, one account per phone). Ops: `me` (accounts[] with profile/devices/live calls without channel names,
   plus combined CDR/sign-ins/failed tagged with `phone`) and `rotate` with `id`, which must be one of that login's accounts
   (Engine source `portal`, which may ONLY rotate). Never returns the current password or other users' data.
13. **Alerts** (`lib/Alerts.php` rules, `lib/Mailer.php` SMTP, `cronTick()` from cron only, never from web requests):
   one digest per >= 5 min (60 s if red), memory in kv `alerts`; failures already in the log when alerts are first
   enabled are NOT mailed. SMTP password is stored in settings (PBX page only; never returned by any remote op).
14. **Gateway auto-ban** (kamailio.cfg.tpl, htable `authfail`/`ban`): public door only. Counted: a 401/407/403 from the
   PBX to a request that carried credentials ($avp gw_auth/gw_src, routes AUTHWATCH/AUTHCHECK) and non-API usernames.
   10 within rolling 10 min → `$sht(ban=>ip)` 1 h, banned IPs get no reply. Never on vpn/lan (shared NAT addresses).
   NOT counted: challenges with `stale=true` (expired nonce; Zoiper re-uses old nonces on every refresh). Any 2xx
   clears that IP's counter. (Field bug, Oct 3 2026: 17.1.0 counted stale 401s and banned the owner's Public phone.)
15. **Changing public IP** (17.2.0). `GW_PUBLIC_IP` = empty | IPv4 | hostname | `auto` (kamailio/pubip.sh, identical
   copy in rtpengine/ via sync-assets.sh). For hostname/auto, `pub_watch` runs as a background child of the main process
   and sends it SIGTERM after the new IP is seen twice in a row (a failed lookup never counts); Docker's restart policy
   brings the container back with the new IP. Kamailio waits for ALL children on shutdown and treats an early child exit
   as a crash, so the watcher must never exit except right after the kill, or when the main process has no same-name
   workers left (= already stopping, e.g. `docker compose stop`). No Docker socket anywhere.
   `ddns` container: stdlib Python, gets ONLY CF_* vars via `environment:` (never `env_file`), always writes
   `proxied: false` (orange cloud can't carry SIP), never publishes a non-global IP, never prints the token.
   PBX side (`lib/PublicIp.php`, `publicIpTick()` from cronTick every 5 min, kv `pubip`): alerts `pubip`, page banner
   with "Use <ip>:<port>" (PBX page only). Settings refuse example text like `10.x.x.x` and drop it on load.

## Verified during the build (sandbox: Asterisk 20, Kamailio 5.7, rtpengine 11)

- Phone REGISTER through Kamailio is authenticated by Asterisk, and Path is stored. The qualify from the PBX reaches the phone.
- Phone → 701 works. Phone → 911 and phone → 703 are blocked. The Jamaica (876) and premium (900) patterns win over the general patterns.
- PBX → 8801 rings the phone through Path. ACK and BYE route correctly.
- On the VPN socket, the phone sees the AstroWarp IP in both Record-Route and SDP.

Gotchas found and fixed:

- Asterisk returns **420** to a Path-ed REGISTER unless it has `Supported: path`.
  Kamailio now adds that header, because Zoiper doesn't send it.
- `loose_route()` returns **-3** for a request from the PBX whose only Route is our own Path,
  but `$du` is already set. So the code checks `$du` rather than the return code.
- Kamailio regex is POSIX, so `(?i)` is invalid. The code uses `$(ua{s.tolower})` instead.
- rtpengine handles two logical interfaces on one IP (`lan/IP`, `vpn/IP!VIP`)
  correctly. The direction pair picks the advertised address.
- FreePBX 17: DB credentials live in /etc/freepbx.conf (`$amp_conf`), NOT in
  `Config->get()`. `calls()` therefore reuses `$this->db` with `asteriskcdrdb.cdr`.
  (Found on the real PBX: "Access denied for user ''@'localhost'".)
- The LXC can't load the rtpengine kernel module, so it runs with `--table=-1` (userspace).
- A placeholder like `10.x.x.x` in `GW_VPN_ADVERTISE_IP` used to crash-loop Kamailio.
  Both entrypoints now validate the value as IPv4 and fall back to GW_LAN_IP with a WARNING.
- Panel runs gunicorn as non-root user `panel`; /ssh is root-only, so the entrypoint
  copies BOTH id_ed25519 and known_hosts to /run/panel. (Testing with `docker compose exec`
  runs as root and hides this. Test through the browser.)
- Public door + account types + DISA, verified Oct 2026 in the sandbox:
  * SIPp through Kamailio: Public→pub door OK; VPN→pub door BLOCKED; VPN→pub door with a forged
    `X-Gw-Net: lan` still BLOCKED (header overwritten); VPN via lan/vpn doors OK.
  * Real DTMF into DISA (Local channel + SendDTMF): right PIN + 5595551234 went out to the trunk; right PIN + 911
    blocked; 5 wrong PINs locked the code, and the correct PIN was then refused while locked.
  * Engine tests: type immutable even for the admin, weak PINs refused, the PIN never stored in clear.
- Share card v2 (after field report "Zoiper scan looks really bad"): ONE dense settings QR (53x53 modules) was
  replaced by one small QR per field (username / password / domains), drawn on an integer pixel grid with
  `image-rendering: pixelated`, tap-to-enlarge full screen. Error correction is picked by length: 'H' for <=32 chars,
  'M' for links. Measured with OpenCV at camera-like quality (45% scale + blur + noise): old dense code 0/10,
  new codes 24/25. Zoiper's in-app "Scan QR" only reads Zoiper's PAID provisioning codes, so users must scan
  with the phone camera / Google Lens. The UI says so.
- Upload/copy mistakes seen in the field: a nested `pbx-api-gateway/pbx-api-gateway`, and
  files landing one folder too high (`rtpengine/` missing). Scripts locate files relative
  to themselves, so keep the repo layout intact.

Verified on real hardware (Oct 2026): Incredible PBX 2026 for Debian 13 (Asterisk 22.9,
FreePBX 17) with a Docker LXC on Proxmox. Zoiper (Android) worked on home Wi-Fi and over
AstroWarp on cellular: registration, allowed and blocked extensions, 88xx inbound, and CDRs.

- Live view (Oct 2026, sandbox Asterisk 20): contacts (door + real IP from Path `received=`), status/RTT joined by
  the 10-char contact hash, inbound call (caller = channel running `Dial(PJSIP/apiu-…)`), outbound call (door +
  dialed number from `GROUP(apiunet)` / `GROUP(apiudst)`), local + remote hang-up, wrong-PIN DISA lines + lockout, panel and
  PBX page rendered in Chromium (desktop light/dark, 390 px phone: no sideways scroll). Asterisk CLI goes through
  FreePBX's AMI `Command` (`$r['data']`) with `asterisk -rx` as fallback. Not yet seen on the real PBX: the AMI
  `data` shape on Asterisk 22 and the `[YYYY-MM-DD HH:MM:SS]` FreePBX log date format (both handled).

- Field report (Oct 1 2026, real PBX): after bumping module.xml to 17.0.2, `apiusers-presence` failed with
  "Unable to locate the FreePBX BMO Class 'Apiusers'" until `fwconsole ma install apiusers` ran. FreePBX won't load
  a module whose files are newer than its registered version. update-pbx.sh now runs `fwconsole ma install apiusers`.
  Bump the version in module.xml whenever the module changes, and always update through update-pbx.sh.

- Conference rooms + feature codes (Oct 2026, sandbox): through the gateway an API user reached conference 8000,
  `555` and `*43`; `*98` (not ticked) and room 8001 (not ticked) were refused; the remote panel's attempt to add `*98`
  was refused by the safety lock; unticking `555` in the panel removed it. `ext-meetme` / `from-internal` were sandbox
  stand-ins: confirm on the real PBX that conference rooms answer through `ext-meetme`.

- Tabs + dashboard (module 17.0.4, Oct 2026): `live()` sends only the last 5 sign-ins / failed + totals + 24 h stats;
  the full lists come from `history()` (ajax `command=history`, panel `/history.json`, remote op `history`) only while
  those tabs are open. Bulk delete = `historyDelete()` (remote op `history_delete`, Engine op `history_clear` audits it):
  sign-in rows are removed from kv `signins`; failed rows are hidden via kv `failed_hidden` {before, ids} because they
  live in the Asterisk log. Events carry ids (old ones get md5-based ids). Blips: same-tick out+in for one user is merged
  in Live::presence (same door+IP = nothing, else "moved"); across ticks, out + in through the same door within
  BLIP_SECONDS (300) removes the "out" line and keeps the session start. Tested in Chromium on both pages (desktop
  light/dark, 390 px phone with no sideways scroll), including delete-selected landing back on the right tab.

- Build 17.1.0 (Oct 3 2026, sandbox): alert e-mails through a real SMTP server (aiosmtpd, STARTTLS + AUTH LOGIN and plain;
  cert check refuses self-signed unless turned off; header injection refused); digests for failed sign-in + VPN-on-public-door,
  then "two places" after the 2-minute overlap; portal in Chromium (desktop dark/light, 390 px phone), stranger login refused,
  401 without the Authentik header, rotate audited as `portal:<login>`, no secret / other users in the JSON.
  Auto-ban with SIPp: 10 wrong passwords from 127.0.0.4 on :5080 → BANNED, right password from that IP ignored, other IPs fine,
  12 wrong passwords on the Wi-Fi door → never banned, 10 non-API usernames → banned.
- Dashboard (17.1.0): reach number next to each name; circle = account type (blue VPN / amber PUB) with the small status dot;
  Live calls card + tile yellow while a call is up; "Signed in now" card red when someone is in two places or a VPN account
  uses the public door; failed rows red, unknown-username rows orange; Failed tab count glows red while there were failures
  in the last 24 h; Dashboard tab shows "● N calls"; audit rows colored (red denied/kill, orange hang-up, yellow history/unlock).

- Field report (Oct 3 2026, real system): after 17.1.0 the Public account MOTO couldn't sign in. Cause: the auto-ban counted
  `401 ... stale=true` (Asterisk's reply to an expired nonce, confirmed with SIPp on Asterisk 20) as a failed login; Zoiper
  re-uses old nonces on re-REGISTER, so ~10 normal refreshes = ban. Fixed in AUTHCHECK (skip stale, reset on 2xx); verified:
  12 parallel stale challenges from one IP → 0 counted, 10 real wrong passwords → still banned.

- Build 17.2.0 (Oct 3 2026, sandbox): real Kamailio 5.7 + rtpengine with GW_PUBLIC_IP=auto against a fake "what is my IP"
  server: 20 Kamailio processes stay up through lookups and failed lookups; an IP change (seen twice) → clean exit 0
  within 4 s (check every 2 s); external SIGTERM (docker stop) → exit in 1 s, watcher gone; restart advertises the new IP
  (rtpengine `pub/...!<new IP>`). First try left Kamailio hanging on shutdown because the watcher (its child) never
  exited, hence the rule in invariant 15. ddns.py against a fake Cloudflare API (tests/ddns_test.py): create, no-op,
  update, orange→grey, bad token, unknown zone, CF_ZONE_ID, private IP refused, several names. PBX page: banner + "Use
  new IP" button, "Check now", placeholder refused (Playwright); cronTick queued a red `pubip` alert.

## Ideas / hardening backlog

- Incredible PBX's default iptables trust 192.168.0.0/16. Consider limiting SSH and the web GUI to admin hosts.
- CDR `userfield` = `apiusers:<id>` on outbound calls, for reports.
- Tailscale/WireGuard on the gateway host itself (see "How a VPN connects").
- Optional per-user schedule (time-of-day) and a monthly-minutes cap for external calls.
- One-scan app setup (DECIDED Oct 2026: not now; users type Zoiper settings by hand from the share-card QRs).
  Options researched, in case it's revisited:
  * Zoiper mobile: only via the Zoiper OEM portal (free signup). Token mode (oem.zoiper.com/docs/token-based-provisioning)
    keeps the password on our server, but needs an unauthenticated token endpoint. No self-hosted deep link exists.
  * Linphone: fully self-hosted. A QR with `linphone-config:https://<host>/<path>?token=...` opens Linphone from the
    phone camera and fetches an XML config. Build it as one-time, short-lived tokens served by the PBX/panel.

## ZOIPER-PRO (phase 2: public TLS, no VPN needed)

Goal: Zoiper Pro connects to `pbx.<domain>:5443` over TLS + SRTP from anywhere. (The plain-UDP public door
`pub`/5080 already exists; the TLS socket must use a different name, e.g. "tls", and the same X-Gw-Net/netcheck
logic. Decide whether "tls" counts as public, i.e. Public accounts only, or as VPN-equivalent.)
Nothing on the PBX changes, because TLS and SRTP end at the gateway.

1. Router: forward TCP 5443 and UDP 40000–40100 to the gateway host.
2. Cert: reuse the reverse proxy's Let's Encrypt cert for `pbx.<domain>`. Copy it with a
   renewal hook, or run certbot with the DNS challenge on the gateway host.
   Mount it into the Kamailio container.
3. Kamailio image: add the `kamailio-tls-modules` package.
4. Kamailio cfg (`kamailio.cfg.tpl`, every `ZOIPER-PRO-TODO`):
   - Set `disable_tcp=no` and `enable_tls=yes`, load `tls.so`, and add `listen=tls:...:5443 advertise <public>:5443 name "pub"`.
   - Treat it as a client socket with `$var(net)="pub"`.
   - Call `add_path_received("pub")`, check for `sip:pub@` in FROM_PBX, and add a `pub` branch in `TO_CLIENT_SOCKET`.
5. rtpengine: add `--interface=pub/<GW_LAN_IP>!<public IP>`.
   - Phone side: `RTP/SAVP` (SDES-SRTP).
   - PBX side: keep `RTP/AVP`.
6. Pike: tighten it. Add fail2ban on the Kamailio log, and consider a GeoIP allow-list at the router.
7. PBX module: add the `client_tls_addr` setting so the UI shows the TLS server (the markers are in Engine/page/panel).
8. With phase 2, users can drop the VPN entirely (useful if a VPN conflicts with other apps).

## PBX footprint (to uninstall)

- `/var/www/html/admin/modules/apiusers`: run `fwconsole ma uninstall apiusers && fwconsole ma remove apiusers`
- `/etc/asterisk/pjsip_apiusers.conf` and `extensions_apiusers.conf`
- The include lines marked `apiusers` in `pjsip_custom.conf` (or `pjsip.endpoint_custom.conf`) and in `extensions_custom.conf`
- `/usr/local/sbin/apiusers-remote`, `/etc/sudoers.d/apiusers-remote`, and user `apiremote` (`userdel -r apiremote`)
- `/usr/local/sbin/apiusers-presence` and `/etc/cron.d/apiusers` (Live view sign-in history + alert e-mails)
- user `apiportal` and `/etc/sudoers.d/apiusers-portal` (only if the user portal key was added)
