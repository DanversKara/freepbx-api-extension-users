# Give your phones a name that never goes stale (Cloudflare, no tunnel)

This guide is for the **public door** (Public accounts that work without a VPN). Skip it if everyone uses the VPN.

## The problem in one sentence

Your home internet address (public IP) changes now and then, and every phone that was told the old number stops working.

## The fix in one sentence

Give your home a **name** like `phone.example.com`, let a small helper keep that name pointing at your home,
and tell phones the name instead of the number.

```
   Mom's phone ──"phone.example.com:5080"──►  Cloudflare DNS  ──►  your home IP today
                                                  ▲
                         ddns container ──────────┘  "my IP changed, update the name"
```

What you need: a domain name whose DNS is on Cloudflare (the free plan is fine), and the public door already
working (router forwards UDP 5080 and UDP 40000-40100 to the gateway; see the README).

> **Why not a Cloudflare Tunnel?** Tunnels and the orange-cloud proxy only carry web traffic. Phone calls are UDP,
> so they need a plain **DNS only (grey cloud)** record and your port forward. The helper makes sure the record stays grey.

---

## Part 1: make a Cloudflare token (2 minutes)

The token lets the helper change DNS for **one** domain and nothing else.

1. Sign in to Cloudflare → click your profile icon (top right) → **My Profile** → **API Tokens**.
2. **Create Token** → next to **Edit zone DNS** click **Use template**.
3. Under **Zone Resources** choose: **Include** → **Specific zone** → your domain.
4. **Continue to summary** → **Create Token**. Copy the token (it's shown once).

You do **not** need to create the DNS record yourself: the helper creates it.

## Part 2: tell the gateway (2 minutes)

On the gateway host (where `docker compose` runs), open `.env` in the `docker-gateway` folder and set:

```ini
GW_PUBLIC_IP=auto
CF_API_TOKEN=paste-the-token-here
CF_RECORD=phone.example.com
```

(Use your own domain. If these lines aren't in your `.env` yet, just add them at the bottom.)

Then start it:

```bash
cd docker-gateway        # the folder with docker-compose.yml
docker compose up -d --build ddns kamailio rtpengine
docker compose logs ddns
```

You should see something like:

```
Cloudflare DDNS on for phone.example.com: checking every 300s, records kept DNS only (grey cloud)
created phone.example.com -> 203.0.113.7
```

| If the log says | Do this |
|---|---|
| `Cloudflare DDNS is OFF` | `CF_API_TOKEN` or `CF_RECORD` is empty in `.env`; fix it and run the `up -d` line again |
| `Invalid access token` | Make a new token (Part 1) and paste it again |
| `no Cloudflare zone found` | Cloudflare → your domain → **Overview** → copy **Zone ID** (right side) → `CF_ZONE_ID=...` in `.env` |
| `could not find the public IP right now` | The internet is down; it tries again by itself |

## Part 3: tell the phones (once)

1. PBX page → **Applications → API Users → Settings** → **Zoiper server without VPN**: `phone.example.com:5080` → **Save**.
2. For each Public person: open their card (or **New password**) and send it again. From now on the card shows
   the name, and it keeps working when your IP changes.

People who already have the IP typed into Zoiper can also just change **Domain** to `phone.example.com:5080`.

---

## What happens when your IP changes

1. Within 5 minutes the helper moves `phone.example.com` to the new IP.
2. Within about 2 minutes the gateway notices the new IP and restarts itself with it (`GW_PUBLIC_IP=auto`).
3. Phones reconnect by themselves on their next sign-in (Zoiper retries every minute or so).
4. If alerts are on, you get an e-mail: "Your home's public internet address changed". You only get a **red**
   one if something needs you, e.g. the name didn't follow within 15 minutes.

The PBX page always shows **Your public IP right now** under Settings, with a **Check now** button.

## Good to know

- **Several names?** `CF_RECORD=phone.example.com,sip.example.com`.
- **Privacy:** anyone can look up `phone.example.com` and see your home IP. That's true of any public door; the
  gateway still only lets in Public accounts, bans IPs that guess passwords, and never forwards anything to the PBX.
- **The token** is only given to the `ddns` container (not to the panel or the portal), and it can only edit
  DNS of that one domain. If it leaks: Cloudflare → API Tokens → **Roll** or **Delete**, then paste the new one.
- **Turn it off:** empty `CF_API_TOKEN` in `.env` and `docker compose up -d ddns`. The record stays as it was.
- **Don't** turn on the orange cloud for this name. If someone does, the helper turns it back to grey.
