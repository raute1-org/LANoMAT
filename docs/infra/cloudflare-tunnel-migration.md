# Moving the app to the venue behind a Cloudflare Tunnel

> **Not run yet.** This is a plan and a checklist, written from the Cloudflare
> and Traefik documentation and from how the app is built today. Nothing here
> was applied to a real zone or a real tunnel. Correct it with what you observe
> in the rehearsal. All hostnames, IDs and tokens are placeholders.

Goal: the app runs on a server at the venue and is still reachable from the
internet under its public name, without a port forward on the uplink router. The
cloud server stays the registration host until shortly before the event and
becomes the fallback afterwards.

## Placeholders

| Placeholder         | Meaning                                                    |
| ------------------- | ---------------------------------------------------------- |
| `<LAN_DOMAIN>`      | public app name (the same name is used inside and outside) |
| `<WS_DOMAIN>`       | Reverb websocket name, a subdomain of `<LAN_DOMAIN>`       |
| `<VENUE_SERVER>`    | the server at the venue that will run the stack            |
| `<CLOUD_SERVER>`    | today's app server (the registration host)                 |
| `<TUNNEL_TOKEN>`    | token of the Cloudflare Tunnel, a secret, never committed  |
| `<COMPOSE_NET>`     | Docker network of the compose project                      |
| `<VENUE_SERVER_IP>` | LAN address of `<VENUE_SERVER>`                            |

## Design

- `cloudflared` runs as a compose service next to Traefik and dials **out** to
  Cloudflare. The uplink router and the MikroTik need no port forward, and the
  double NAT does not matter.
- The public hostnames (`<LAN_DOMAIN>`, `<WS_DOMAIN>`) are CNAMEs to the tunnel,
  created in Cloudflare. The zone must be managed by Cloudflare (it is).
- Inside the LAN, the **same names** resolve to `<VENUE_SERVER_IP>` through a
  static wildcard entry on the MikroTik
  ([lan-tls-certificates.md](lan-tls-certificates.md), step 1). LAN clients
  therefore never leave the building, and an uplink outage does not break them.
- Traefik keeps terminating TLS for the LAN side with the DNS-01 wildcard
  certificate. For the public side, Cloudflare terminates TLS at its edge and
  talks to Traefik over the origin connection.
- Discord OAuth, the Steam callback and `STEAM_ALLOWED_HOSTS` keep working
  because the public name does not change.

## What changes in the app, and what does not

| Topic                   | Effect                                                                                                                                                                    |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Client IP               | Behind the tunnel the real address arrives in `CF-Connecting-IP`. Login and check-in rate limits count per IP, so they would otherwise count all visitors as one address. |
| Traefik headers         | Traefik overwrites `X-Forwarded-*` from untrusted sources. The `cloudflared` container's network must be listed in the entrypoint's `forwardedHeaders.trustedIPs`.        |
| Upload size             | Cloudflare's free plan caps a request body at 100 MB. The gallery allows 100 MiB and shared files 200 MB. From outside, larger uploads fail; inside the LAN they work.    |
| Websockets (Reverb)     | Supported through the tunnel. `REVERB_HOST` stays `<WS_DOMAIN>`, `REVERB_SCHEME=https`, `REVERB_PORT=443`.                                                                |
| Pelican, voice, jukebox | They become LAN neighbours of the app. The app-side WireGuard tunnel from [wireguard-tunnel.md](wireguard-tunnel.md) is no longer needed for them.                        |
| Discord login           | Needs the internet. Active sessions survive an outage, a new login does not. The roadmap already records the offline-login risk as accepted.                              |

Work needed before the move (separate PRs, each with tests):

1. Trust `CF-Connecting-IP` for the client address, only from the tunnel network.
2. Traefik `forwardedHeaders.trustedIPs` for `<COMPOSE_NET>`.
3. A `cloudflared` compose service (profile `prod`) reading `TUNNEL_TOKEN` from
   `.env`, started explicitly because `deploy.sh` starts only named services.
4. A decision on the public upload cap (lower the caps for the public routes, or
   accept that big uploads only work from inside).

## Step 1: create the tunnel (owner, in the Cloudflare dashboard)

Create a remotely managed tunnel and copy its token into `.env` on
`<VENUE_SERVER>` yourself (`TUNNEL_TOKEN=<TUNNEL_TOKEN>`). Add two public
hostnames: `<LAN_DOMAIN>` and `<WS_DOMAIN>`, both with the service URL of
Traefik inside the compose network. Prefer HTTPS to Traefik with the origin
server name set to `<LAN_DOMAIN>` and verification on, which needs the local
certificate to be valid for that name. Plain HTTP on the internal network is the
fallback if you do not want to depend on the certificate for this hop.

The token is a secret. Do not paste it into chat, commits, issues or
screenshots. Rotate it in the dashboard if it was ever exposed.

## Step 2: prepare `<VENUE_SERVER>` (a week or more before the event)

1. Base host as in [proxmox-test-vm-lancache.md](proxmox-test-vm-lancache.md)
   (hardening, Docker). Same `.env` shape as
   [deploy-lan-raute1.md](../deploy-lan-raute1.md), with **the same `APP_KEY`** as
   `<CLOUD_SERVER>`: encrypted columns and cookies depend on it.
2. Certificate: [lan-tls-certificates.md](lan-tls-certificates.md) (DNS-01,
   staging first). No port 80 or 443 reachable from the internet is needed.
3. MikroTik: the static wildcard entry for `<LAN_DOMAIN>`.
4. Bring the stack up **without** the tunnel first and check it from a LAN client
   by name.

## Step 3: rehearsal (copy, not cut-over)

1. On `<CLOUD_SERVER>` take a backup with the existing script and restore it on
   `<VENUE_SERVER>` following [restore-runbook.md](../ops/restore-runbook.md),
   including the uploads volume.
2. Start `cloudflared` there with a **separate rehearsal hostname** (not
   `<LAN_DOMAIN>`), so production traffic is untouched.
3. Check: login by Discord, registration, check-in by QR, a Reverb live page, the
   beamer, an upload from inside and one from outside, the real client address in
   the app (log or admin) and a rate limit hit from one outside address.
4. Compare the row counts with the restore drill output.

## Step 4: cut-over (about a week before the event)

1. Announce a short freeze. On `<CLOUD_SERVER>` stop the app and queue
   (`docker compose --profile prod stop app queue scheduler reverb-prod`) so no
   write is lost.
2. Take the last backup, restore it on `<VENUE_SERVER>`, start the stack.
3. In Cloudflare, point `<LAN_DOMAIN>` and `<WS_DOMAIN>` at the tunnel of
   `<VENUE_SERVER>`. Lower the DNS TTL a day earlier.
4. Run `lanomat:preflight` and the test checklist (section 7 of the deploy
   runbook).
5. Keep `<CLOUD_SERVER>` stopped, not deleted, for the whole event.

## Rollback

Point the two hostnames back at `<CLOUD_SERVER>` and start its stack again. The
data written on `<VENUE_SERVER>` after the cut-over must be dumped and restored
there first, so decide the direction of the data before you switch, not after.

## Alternatives considered

| Option                                                                                    | Verdict                                                                                                                                                                                                                                                                                                                                                |
| ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Cloudflare Tunnel (this document)                                                         | Best when the cloud server should go away: free, DDoS protection, no public server to run. Costs the 100 MB request cap and a dependency on a third party.                                                                                                                                                                                             |
| Cloud Traefik routing to the venue over WireGuard                                         | Needs no new software when the MikroTik tunnel from [wireguard-tunnel.md](wireguard-tunnel.md) exists anyway: add a route in the existing Traefik on `<CLOUD_SERVER>` that points at the venue app's tunnel address. Upload caps stay under your control. The cloud server remains the public entry point. Recommended while `<CLOUD_SERVER>` is kept. |
| Pangolin (self-hosted tunnelled reverse proxy with its own Traefik and a WireGuard agent) | Not needed here. It would replace the Traefik you already run, and its strengths (user management, SSO, many services behind one entry) are not required. Unverified: check the project's own documentation for licence and limits before reconsidering.                                                                                               |

## Risks

| Risk                                         | Mitigation                                                             |
| -------------------------------------------- | ---------------------------------------------------------------------- |
| Venue uplink or power fails before the event | Cut-over late (one week before), keep `<CLOUD_SERVER>` as the fallback |
| Wrong client IP defeats the rate limits      | Trusted-header change with a test, checked in the rehearsal            |
| Large uploads fail from outside              | Decide the public caps; tell users                                     |
| Token leaked                                 | Dashboard rotation; token only in the server `.env`                    |
| `APP_KEY` differs after the move             | Copy it before the restore; the restore drill verifies decryption      |
| Two stacks write at the same time            | Stop the old app and queue before the final backup                     |

## Open questions

- Origin connection from `cloudflared` to Traefik: HTTPS with verification, or
  plain HTTP on the internal network.
- Public upload caps: lower them for the public routes, or leave them.
- Whether the WireGuard tunnel stays for anything after the move (probably not).
