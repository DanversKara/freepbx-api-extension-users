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
| `pbx-module/apiusers/views/page.php` | PBX admin page |
| `pbx-module/apiusers/bin/apiusers-remote` | SSH forced command (JSON in / JSON out) |
| `pbx-module/apiusers/assets/share-card.js` | Share card (QR, PNG, email, copy, print), all client-side. **Source copy**; `scripts/sync-assets.sh` copies it + `qrcode.js` to `docker-gateway/panel/static/`. The PBX page inlines it (no FreePBX asset routing needed); the panel serves `/static/` |
| `pbx-module/apiusers/assets/qrcode.js` | Vendored qrcode-generator 2.0.4 (MIT) + UTF-8 shim, UMD footer removed (avoids AMD loader clashes) |
| `docker-gateway/kamailio/kamailio.cfg.tpl` | SIP front door (envsubst `GW_*`) |
| `docker-gateway/rtpengine/entrypoint.sh` | Audio relay, interfaces `lan` + `vpn` |
| `docker-gateway/panel/app.py` + `templates/index.html` | Remote panel (Flask, stateless) |
| `scripts/install-pbx.sh` | Run on PBX. `--add-key` installs the panel's SSH key |
| `scripts/setup-docker.sh` | Run on the gateway host |
| `scripts/proxmox-firewall.sh` | Optional, Proxmox host: rewrites `/etc/pve/firewall/<CT>.fw` (env CT/NPM_IP/PBX_IP) |
| `docs/npm-authentik.md` | NPM proxy host + Authentik forward-auth |
| `tests/engine_test.php` | `php tests/engine_test.php`, must print ALL PASSED |

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
6. **911 warning:** a remote user's 911 call goes out with the PBX's E911 address
   (the owner's house). The UI warns about this. Keep 911 off for remote people.

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

Goal: Zoiper Pro connects to `pbx.<domain>:5443` over TLS + SRTP from anywhere.
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
