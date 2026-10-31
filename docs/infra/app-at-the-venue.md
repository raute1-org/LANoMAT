# Running the app at the venue, with the cloud server as the public entry

Plan and checklist for moving the app from the cloud server to a server at the
venue. The cloud server stays: it remains the public entry point, and it is the
fallback.

**Status.** The connection mechanism (TLS pass-through with the PROXY protocol,
keeping the real client address) was rehearsed with two Traefik containers on one
machine. The rest of this document is not run against real hosts: treat it as a
checklist for the rehearsal and correct it with what you observe. All hostnames,
addresses and keys are placeholders.

## Placeholders

| Placeholder         | Meaning                                                                         |
| ------------------- | ------------------------------------------------------------------------------- |
| `<LAN_DOMAIN>`      | public app name; the same name is used inside and outside                       |
| `<CLOUD_SERVER>`    | today's app server, the public entry point                                      |
| `<VENUE_SERVER>`    | the server at the venue that will run the stack                                 |
| `<WG_APP_ADDR>`     | tunnel address of `<CLOUD_SERVER>` ([wireguard-tunnel.md](wireguard-tunnel.md)) |
| `<WG_VENUE_ADDR>`   | tunnel address of the venue side (the MikroTik or `<VENUE_SERVER>`)             |
| `<VENUE_SERVER_IP>` | LAN address of `<VENUE_SERVER>`                                                 |

## Design

```text
public client ──► <CLOUD_SERVER> Traefik ──(WireGuard, PROXY v2)──► <VENUE_SERVER> Traefik ──► app
LAN client ───────────────────────────────────────────────────────► <VENUE_SERVER> Traefik ──► app
                 (local DNS: <LAN_DOMAIN> → <VENUE_SERVER_IP>)
```

- **TLS is not terminated on the cloud server.** Its Traefik passes the TLS
  connection through by SNI (`<LAN_DOMAIN>` and its subdomains, so the Reverb
  name is covered). One certificate lives at the venue: the DNS-01 wildcard from
  [lan-tls-certificates.md](lan-tls-certificates.md). Inside and outside see the
  same certificate and the same routers.
- **The real client address survives** through a PROXY protocol v2 header that
  the cloud Traefik writes and the venue Traefik reads. Login and check-in rate
  limits keep counting per person. No change in the app is needed.
- **LAN clients go straight to the venue** through local DNS and never touch the
  cloud server. An uplink outage does not affect them.
- The body-size caps are the ones already in the venue's Traefik, so the 100 MB
  limit of a third-party proxy does not exist here.

## What was rehearsed

Two Traefik v3.6 containers on one Docker network: the "cloud" one with a TCP
router (`HostSNI`, `tls.passthrough`) and a TCP service with
`proxyprotocol.version=2`, the "venue" one with
`entryPoints.websecure.proxyProtocol.trustedIPs` set to the cloud container's
address, and a small HTTP echo service behind the venue router.

| Check                                              | Result                                                                 |
| -------------------------------------------------- | ---------------------------------------------------------------------- |
| Public path, address seen by the backend           | the real client address (not the cloud Traefik's)                      |
| LAN client straight to the venue, no PROXY header  | works, real address seen                                               |
| Client sends its own `X-Forwarded-For`, both paths | ignored and replaced with the real address                             |
| Docker provider with only an `address` label       | logs "port is missing" on every refresh; add a `server.port` label too |

## Repository support

- `compose.yml`, service `venue-entry`: carries only the labels, behind its own
  profile (`venue-entry`). It is **not** started by `deploy.sh`. Start it on
  `<CLOUD_SERVER>` at cut-over.
- `compose.yml`, `traefik` service: the flag
  `entrypoints.websecure.proxyprotocol.trustedips` from `PROXY_PROTOCOL_TRUSTED_IPS`.
  The default is a loopback no-op, so existing deployments are unchanged.
- `.env` values: on `<CLOUD_SERVER>` set `VENUE_UPSTREAM=<WG_VENUE_ADDR>:443`; on
  `<VENUE_SERVER>` set `PROXY_PROTOCOL_TRUSTED_IPS=<WG_APP_ADDR>/32`.

## Step 1: prepare `<VENUE_SERVER>` (a week or more before the event)

1. Base host as in [proxmox-test-vm-lancache.md](proxmox-test-vm-lancache.md).
   Same `.env` shape as [deploy-lan-raute1.md](../deploy-lan-raute1.md) with **the
   same `APP_KEY`** as `<CLOUD_SERVER>`: encrypted columns and cookies depend on it.
2. Certificate by DNS-01, staging first ([lan-tls-certificates.md](lan-tls-certificates.md)).
   No port 80 or 443 needs to be reachable from the internet at the venue.
3. Tunnel: [wireguard-tunnel.md](wireguard-tunnel.md), variant "tunnel on the venue
   router". Allow `<WG_APP_ADDR>` to reach `<VENUE_SERVER_IP>:443` in the router's
   forward rules.
4. `PROXY_PROTOCOL_TRUSTED_IPS=<WG_APP_ADDR>/32`, recreate Traefik.
5. Local DNS: the static wildcard entry for `<LAN_DOMAIN>` on the MikroTik.
6. Bring the stack up and check it from a LAN client by name.

## Step 2: rehearsal (a copy, not a cut-over)

1. Take a backup on `<CLOUD_SERVER>` and restore it on `<VENUE_SERVER>` following
   [restore-runbook.md](../ops/restore-runbook.md), uploads included.
2. Use a **separate rehearsal name** under the same zone for the pass-through (a
   second `HostSNI` route and a matching certificate), so the production name is
   untouched.
3. Check: Discord login, registration, check-in by QR, a Reverb live page, the
   beamer, an upload from inside and from outside, and the **client address in the
   app**: hit a rate limit from one outside address and confirm that a second
   outside address is not affected.
4. Compare the row counts with the restore drill output.

## Step 3: cut-over (about a week before the event)

1. Announce a short freeze. On `<CLOUD_SERVER>` stop the app stack (`app`, `queue`,
   `scheduler`, `reverb-prod`) so no write is lost.
2. Take the last backup, restore it on `<VENUE_SERVER>`, start the stack there.
3. On `<CLOUD_SERVER>` set `VENUE_UPSTREAM` and start the entry:
   `docker compose --profile prod --profile venue-entry up -d venue-entry`.
   The cloud's own app routers are gone because the app is stopped.
4. Run `lanomat:preflight` and the test checklist (section 7 of the deploy runbook).
5. Keep the cloud app stopped, not deleted, for the whole event.

## Rollback

Stop `venue-entry` on `<CLOUD_SERVER>` and start its own app stack again. Data
written on `<VENUE_SERVER>` after the cut-over must be dumped and restored on the
cloud server first, so decide the direction of the data before you switch.

## Offline operation inside the LAN

With the local name resolving to `<VENUE_SERVER_IP>`, LAN clients keep working
without internet.

Works: the site, check-in, seat map, schedule, catering, tournaments with the
live view over Reverb, the beamer, OBS overlays, voice, the jukebox, game servers.
The UI loads nothing from external hosts.

Does not work: a **new** Discord login (sessions that already exist survive; ask
everyone to log in once before arriving), Steam and Twitch linking, everything
from the Discord bot, Discord avatars (their URLs point to Discord's CDN; check
how the pages look without them in the rehearsal), and downloads of games the
LanCache has not cached yet.

The certificate stays valid offline. The 90-day wildcard renews about 30 days
before expiry, so check the expiry date a week before the event and make sure it
covers it by at least two weeks.

**Clients that bypass local DNS.** Android "Private DNS", iCloud Private Relay and
browsers using DNS over HTTPS resolve the public name and then cannot connect
without internet. On the MikroTik: hand out the LanCache DNS as the only resolver
by DHCP, block DNS over TLS (TCP 853) in the forward chain, and answer
`use-application-dns.net` with NXDOMAIN (this switches DoH off in Firefox and
Chrome). Put a notice up: "turn off Private DNS and Private Relay on the Wi-Fi".

Rehearsal: unplug the uplink, then test the site, a check-in and a live page from
two phones, once with and once without Private DNS.

## Risks

| Risk                                         | Mitigation                                                                          |
| -------------------------------------------- | ----------------------------------------------------------------------------------- |
| Venue uplink or power fails before the event | Cut over late (one week before), keep `<CLOUD_SERVER>` as the fallback              |
| Wrong client address defeats the rate limits | Rehearsed locally; re-check in the rehearsal with two outside addresses             |
| Tunnel down: the public site is unreachable  | The cloud entry has no fallback page; use rollback; monitor the tunnel in preflight |
| `APP_KEY` differs after the move             | Copy it before the restore; the restore drill verifies decryption                   |
| Two stacks write at the same time            | Stop the cloud app and queue before the final backup                                |
| PROXY header from an untrusted source        | `trustedips` lists only `<WG_APP_ADDR>/32`; the default is loopback                 |

## Alternatives considered

| Option                                            | Verdict                                                                                                                                                                                                                                                          |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Cloud Traefik over WireGuard (this document)      | Chosen: no new software, `<CLOUD_SERVER>` is kept anyway, one certificate, real client address, caps under your control.                                                                                                                                         |
| Cloudflare Tunnel with `cloudflared` at the venue | Fits when the cloud server should go away: free, DDoS protection, no public server. Costs a request cap of 100 MB on the free plan (gallery and file uploads would exceed it) and a third-party dependency; the client address then comes in `CF-Connecting-IP`. |
| Pangolin (self-hosted tunnelled reverse proxy)    | Not needed. It would replace the Traefik already in use; user management and SSO are not required. Unverified: check the project's own documentation for licence and limits before reconsidering.                                                                |

## Open questions

- Does the venue Traefik accept the PROXY header from the MikroTik's tunnel
  address instead, if the router and not the server ends the tunnel? The trusted
  address is the one that connects to the venue Traefik; check it in the rehearsal.
- A maintenance page on the cloud server for the time the tunnel is down.
- Whether the venue router should forward the whole `<WG_CIDR>` or only the one
  address of `<CLOUD_SERVER>` to `<VENUE_SERVER_IP>:443` (default: only that one).
