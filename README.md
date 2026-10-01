# PBX API Users Gateway

**Give family and friends a phone line into your home PBX (Incredible PBX / FreePBX 17) without
giving them your PBX.** They get locked-down Zoiper accounts that aren't real extensions. You decide
exactly what each person can dial, and your PBX is never exposed to the internet.

This repository has **two editions**. Pick the one that fits how your users will connect.

| Folder | Edition | In one line |
|---|---|---|
| [`vpn-only/`](vpn-only/) | **VPN only** | Every user connects through a VPN (AstroWarp, Tailscale, WireGuard) or your home Wi-Fi. **No ports opened on your router.** |
| [`vpn-plus-public/`](vpn-plus-public/) | **VPN + Public door + DISA** | Everything in VPN only, **plus** an optional port-forwarded "public door" for people who won't run a VPN, with **locked-down Public accounts** and a **PIN-protected dial-out code (DISA)**. |

> Both editions share the same core: the FreePBX module, the Docker gateway (Kamailio + rtpengine),
> the remote panel behind Nginx Proxy Manager + Authentik, the share card with QR codes, the kill switch,
> call logs and the audit log. `vpn-plus-public` is the newer build and also adds the Live view. Each folder has its own full README with install steps.

---

## Which one should I use?

```
Will every person who gets an account install a VPN app (AstroWarp / Tailscale / WireGuard)?
│
├── Yes ─────────────────────────────► vpn-only/
│                                      Nothing exposed to the internet. Simplest and safest.
│
└── No, some people won't use a VPN ─► vpn-plus-public/
                                       Those people get a "Public account" through one port forward.
                                       Public accounts are locked down permanently (no outside calls,
                                       no 911), with an optional PIN dial-out code for US/Canada calls.
```

**Not sure?** Start with **`vpn-only`**. You can move to `vpn-plus-public` later without losing users
(see [Upgrading](#upgrading-from-vpn-only-to-vpn--public)). And if you install `vpn-plus-public` but never
open the public door, it behaves exactly like VPN only.

---

## Side-by-side

| | **VPN only** | **VPN + Public door + DISA** |
|---|---|---|
| **How users connect** | VPN or home Wi-Fi | VPN, home Wi-Fi, **or** the public door (no VPN) |
| **Ports opened on your router** | **None** | **UDP 5080** + **UDP 40000–40100** → the gateway (never the PBX) |
| **Account types** | One kind | **VPN accounts** and **Public accounts** (chosen at creation, can never be changed) |
| **Call ticked extensions** | ✅ | ✅ both types |
| **Outside calls (US/Canada)** | Optional per user (admin, PBX page only) | VPN accounts: optional. **Public accounts: never directly**, only via DISA + PIN |
| **911 / E911** | Optional per user (admin, PBX page only) | VPN accounts: optional. **Public accounts: never, not even through DISA** |
| **International** | Optional per user | VPN accounts: optional. Public accounts: never |
| **DISA dial-out code** (`*3472` + PIN) | — | ✅ Public accounts only. PIN stored as a salted hash; 5 wrong PINs lock it for 1 h; 911 always blocked |
| **What a stolen login can do from the internet** | Nothing: it only works through your VPN | VPN-account login: **nothing** (refused at the public door). Public-account login: call the ticked extensions only; DISA still needs the PIN |
| **Encryption** | VPN encrypts everything | VPN users: encrypted. Public door: **plain UDP** (free Zoiper has no TLS/SRTP) |
| **Internet scanners** | Can't see anything | Will find UDP 5080 (rate-limited and filtered, but you'll see the noise) |
| **Remote panel safety lock** | Can't turn on outside calls / 911 / intl | Same, **plus** can't create Public accounts or set or enable DISA |
| **Live view** (who's signed in, live calls + hang up, sign-in history, failed sign-ins) | — | ✅ PBX page and remote panel |
| **Gateway refuses non-API usernames** (your real extensions can't be tried through it) | — (only reachable over your VPN/LAN) | ✅ |
| **Extra setup** | VPN only | VPN + router forwards + `GW_PUBLIC_IP` in `.env` |
| **Best for** | Family/friends who'll install one VPN app | Mixed groups: some on VPN, some who just want "an app and a login" |

---

## What's the same in both

- **The PBX is never exposed.** Phones talk to a gateway (Kamailio) in Docker. Only the gateway talks to the PBX, over your LAN.
- **Per-user permissions** live in the PBX dialplan: everything not explicitly allowed is refused.
  Premium 900/976 numbers and Caribbean "looks domestic" area codes are always blocked unless you allow international.
- **No transfers:** users can't bounce a call to a number they aren't allowed to dial.
- **Safety lock:** the remote web panel (behind Authentik) can never turn on paid calling or 911. Only the PBX page can.
- **Share card:** QR codes plus a ready-to-send image, email or text with the connection details.
- **Limits, kill switch, password rotation, call logs, audit log.**
- Tested on Incredible PBX 2026 (Debian 13, Asterisk 22, FreePBX 17) with the gateway in a Proxmox LXC.

---

## ⚠️ Read before you choose: 911 and money

- **911 from a remote user goes out with *your* E911 address**, so responders would go to your house.
  Only enable 911 for VPN accounts used by people physically at your address. Public accounts can never call 911.
- **Call forwarding is the one leak to watch, in both editions.** If an extension you let someone call forwards to
  an outside number (Call Forward, Follow Me, a ring group with a cell number), that forwarded call goes out
  through your trunk and costs money. Only tick extensions that don't forward outside, and keep per-user limits low.
- **The public door is plain, unencrypted SIP.** Fine for calling family, but don't use it for anything sensitive.
  For an encrypted public option, see the Zoiper Pro / TLS roadmap in each edition's `CLAUDE.md`.

---

## Upgrading from VPN only to VPN + Public

Your users, passwords, permissions and remote token are kept. They live in the PBX database.

1. **PBX:** copy `vpn-plus-public/` to the PBX and run `bash scripts/update-pbx.sh`.
   Existing users automatically become **VPN accounts**.
2. **Gateway:** copy `vpn-plus-public/` over your gateway folder, keeping your `docker-gateway/.env` and `docker-gateway/ssh/`,
   then run `docker compose up -d --build`.
3. **Optional, to open the public door:** add `GW_PUBLIC_IP=<your public IP or DDNS name>` and `GW_PUB_PORT=5080` to `.env`,
   forward **UDP 5080** and **UDP 40000–40100** to the gateway, and set "Zoiper server without VPN" on the PBX page.

> Never forward 5070, 5071 or 5072. Those are the VPN, PBX and home-Wi-Fi doors, and the gateway trusts them more.

---

## Repository layout

```
README.md            ← you are here (pick an edition)
vpn-only/            ← edition 1: full project, VPN/LAN access only
vpn-plus-public/     ← edition 2: full project + public door, account types, DISA
```

Each edition contains:

```
pbx-module/apiusers/   FreePBX 17 module (source of truth)
docker-gateway/        Kamailio + rtpengine + remote panel (Docker)
scripts/               install / setup / firewall helpers
docs/                  NPM + Authentik guide
tests/                 php tests/engine_test.php
README.md              full install guide for that edition
CLAUDE.md              deep technical notes for maintainers / AI assistants
```

---

## License

GPL-3.0-or-later. Includes [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) by Kazuhiko Arase (MIT).

*Not affiliated with Sangoma, FreePBX, Incredible PBX, GL.iNet, Tailscale, WireGuard or Zoiper.
Use at your own risk: you are responsible for your phone bill and your 911 setup.*
