# TLS certificates for LAN services (Traefik, DNS-01, Cloudflare or Hetzner DNS)

A runbook for giving the services of a LANoMAT event real, browser-trusted TLS certificates without the
services being reachable from the internet, and without installing a CA on every phone. It uses a wildcard
certificate for a name that only resolves inside the venue, obtained through the ACME **DNS-01** challenge
with the DNS provider's API. Addresses, names and tokens are placeholders.

> **Status:** the **Cloudflare** path was run against a real zone with the Let's Encrypt **staging** CA
> (Traefik v3.6, which bundles lego v5.3.1), see "Verified" below. The **Hetzner** path, the production CA and
> the venue DNS (step 1) have **not** been run yet; tick the checklist at the end as you go.

## Verified (Cloudflare, staging CA)

A throwaway Traefik with exactly the resolver of step 3 (token as `CF_DNS_API_TOKEN_FILE`, public `resolvers`,
`keyType: EC256`, `main` plus wildcard `sans`) was run twice against a throwaway name below a real Cloudflare zone:

- One certificate with the apex and the wildcard as SANs was issued; the served chain was the "(STAGING)" CA and
  an EC key. A sub-name (`x.<LAN_DOMAIN>`) got the same certificate.
- Both challenges took about 20 seconds each, with `Validation retrying ... status=pending` lines in the debug
  log: normal while the TXT record propagates, not an error.
- The token needed only _Zone / Zone / Read_ and _Zone / DNS / Edit_ on the one zone. No account id is needed.
- No `_acme-challenge` TXT record was left in the zone afterwards.
- The same recipe was then run against the **production** CA: one wildcard certificate for the apex and `*.<LAN_DOMAIN>`, valid 90 days. The staging lifetime below is a staging property.
- The staging certificate's `notAfter` was about 64 days out, not 90. Read `notAfter` from the served certificate
  instead of relying on a fixed lifetime when you plan the issue date and the expiry check.

## Why this approach

| Option                                  | Phones and laptops trust it?       | Needs internet at the venue?             | Verdict                                                    |
| --------------------------------------- | ---------------------------------- | ---------------------------------------- | ---------------------------------------------------------- |
| `mkcert` (local CA)                     | only machines with the CA imported | no                                       | development machine only                                   |
| Internal CA (`step-ca`)                 | only after importing the root CA   | no                                       | fixed devices (beamer PC, OBS, orga laptops)               |
| **Wildcard certificate via DNS-01**     | **yes, out of the box**            | only to issue and renew (every ~60 days) | **use this for everything participants open in a browser** |
| HTTP-01 on a public name (as `lan.<…>`) | yes                                | yes, the host must be reachable          | the public app server only                                 |

DNS-01 proves control of a name by a TXT record, so the server never has to be reachable from outside. The
certificate is valid for a limited time (see `notAfter`; about 90 days in production): issue it a few days before
the event while the host has internet, and it lasts the whole weekend.

Things that need a trusted certificate in a browser: the app itself, anything the app page embeds or calls
(an `https` page cannot open `http` or `ws` endpoints, "mixed content"), the Music Assistant web UI, the
Pelican panel when it is used from participant devices, and a self-hosted LiveKit endpoint (it does not accept
self-signed certificates).

## Placeholders

| Placeholder         | Meaning                                                                                   |
| ------------------- | ----------------------------------------------------------------------------------------- |
| `<LAN_DOMAIN>`      | a name below a domain you own, used only inside the venue, e.g. `lan-local.<your-domain>` |
| `<VENUE_SERVER_IP>` | LAN address of the host that runs Traefik                                                 |
| `<ZONE>`            | the DNS zone that contains `<LAN_DOMAIN>`                                                 |
| `<DNS_API_TOKEN>`   | API token of the DNS provider, root-only file, never committed                            |
| `<ACME_EMAIL>`      | e-mail for the ACME account (expiry notices)                                              |
| `<TRAEFIK_DIR>`     | compose project directory of the venue Traefik                                            |

## Step 1: local DNS for the venue (split-horizon)

The wildcard name must resolve to `<VENUE_SERVER_IP>` **inside** the venue only. Do not create A records for it
in the public zone (it would publish an internal address and is not needed for DNS-01).

**The venue's name resolution chain.** Clients ask the LanCache DNS (`lancachenet/lancache-dns`, BIND9). It
answers the cache domains itself and forwards **everything else** to `UPSTREAM_DNS`
([proxmox-test-vm-lancache.md](proxmox-test-vm-lancache.md)). Do not try to add a zone to the LanCache DNS: its
`named.conf` includes only the generated `cache.conf`, which is rebuilt on every start, so a hand-made zone is
lost on the next image update. Put the record where the queries are forwarded to instead: the venue router.

**With a MikroTik as the venue router (the stack's LAN-only router behind the router with internet).** Point
`UPSTREAM_DNS` of the LanCache DNS at the MikroTik's LAN address, let the MikroTik answer for the venue name, and
let it forward the rest to its own upstream (the internet router). RouterOS has no DNS rebind protection, so the
private answer is returned as is. In RouterOS 7:

```routeros
# let the router act as a DNS server for the LAN
/ip dns set allow-remote-requests=yes servers=<INTERNET_ROUTER_IP>

# one entry answers <LAN_DOMAIN> and every name below it
/ip dns static add name=<LAN_DOMAIN> address=<VENUE_SERVER_IP> match-subdomain=yes comment="LANoMAT TLS names"
```

On RouterOS 6 (no `match-subdomain`) use a regular expression instead:
`/ip dns static add regexp=".*\\.<LAN_DOMAIN>$" address=<VENUE_SERVER_IP>` plus a plain entry for the apex. The
exact option names depend on the RouterOS version; check `/ip dns static add ?` on the router.

Also check the router's input firewall: UDP and TCP 53 must be allowed from the LanCache host (it forwards to the
router). DHCP hands out the LanCache DNS as the clients' resolver (`/ip dhcp-server network ... dns-server=`).
The reverse layout also works (clients use the MikroTik, which forwards the cache domains to the LanCache DNS
with `type=FWD` static entries); pick one and keep it.

**If the upstream is a public resolver instead of a router you control** (the LANoMAT LanCache setup defaults
`UPSTREAM_DNS` to a public address), the venue name cannot be answered anywhere. Run a small authoritative
resolver and make it the `UPSTREAM_DNS`: for example CoreDNS with a `template` block for `<LAN_DOMAIN>` that
answers `<VENUE_SERVER_IP>` and a catch-all `forward` to public resolvers. This variant is **untested** here.

Check from a phone on the venue Wi-Fi that `app.<LAN_DOMAIN>` resolves to `<VENUE_SERVER_IP>` (and that
`example.com` still resolves) before issuing certificates.

## Step 2: API token at the DNS provider

Give Traefik the smallest token that can edit TXT records in the one zone.

**Cloudflare** (provider `cloudflare`, variable `CF_DNS_API_TOKEN`): create an API token with
_Zone / Zone / Read_ and _Zone / DNS / Edit_, scoped to `<ZONE>` (lego resolves the zone id itself; the
stricter split into `CF_ZONE_API_TOKEN` and `CF_DNS_API_TOKEN` is possible, see the lego Cloudflare page).

**Hetzner DNS** (provider `hetzner`, variable `HETZNER_API_TOKEN` in the current lego documentation): create an
API token for the DNS zone. Hetzner has been moving DNS from the old DNS Console to its newer API, and the
variable name depends on the lego version bundled in your Traefik release (older releases read
`HETZNER_API_KEY`). Check the Traefik log on the first run: an unknown or missing variable is reported as an
error naming the variable, and the lego provider page for your Traefik version lists the right one.

Store the token as a root-only file and pass it with the `_FILE` suffix variant so it never appears in
`docker inspect` output or a compose file:

```bash
sudo install -d -m 700 <TRAEFIK_DIR>/secrets
sudo sh -c 'umask 077; printf %s "<DNS_API_TOKEN>" > <TRAEFIK_DIR>/secrets/dns_api_token'
```

## Step 3: Traefik resolver and router

One certificate resolver per DNS provider (Traefik supports exactly one DNS provider per resolver). In the
static configuration of the venue Traefik:

```yaml
# traefik.yml (static configuration); use ONE of the two provider values
certificatesResolvers:
    lan:
        acme:
            email: <ACME_EMAIL>
            storage: /letsencrypt/acme-lan.json
            keyType: EC256
            # Step 4: switch to staging first, then remove this line
            caServer: https://acme-staging-v02.api.letsencrypt.org/directory
            dnsChallenge:
                provider: cloudflare # or: hetzner
                # Ask PUBLIC resolvers for the challenge record. The venue resolver answers
                # <LAN_DOMAIN> locally and would never see the TXT record.
                resolvers:
                    - 1.1.1.1:53
                    - 8.8.8.8:53
```

Pass the token to the Traefik container (Cloudflare shown; for Hetzner use `HETZNER_API_TOKEN_FILE`):

```yaml
# compose.yml of the venue Traefik
services:
    traefik:
        environment:
            CF_DNS_API_TOKEN_FILE: /run/secrets/dns_api_token
        volumes:
            - <TRAEFIK_DIR>/secrets/dns_api_token:/run/secrets/dns_api_token:ro
            - traefik_acme_lan:/letsencrypt
```

Request the wildcard on the router (labels of any service that should use it); `main` plus the wildcard `sans`
issue one certificate for the apex and for every sub-name:

```yaml
labels:
    traefik.http.routers.example.rule: Host(`app.<LAN_DOMAIN>`)
    traefik.http.routers.example.tls.certresolver: lan
    traefik.http.routers.example.tls.domains[0].main: <LAN_DOMAIN>
    traefik.http.routers.example.tls.domains[0].sans: '*.<LAN_DOMAIN>'
```

A wildcard covers exactly one label (`a.<LAN_DOMAIN>`, not `a.b.<LAN_DOMAIN>`) and does not cover the apex, which
is why the apex is listed as `main`.

## Step 4: test against the staging CA, then issue the real one

1. Start Traefik with the `caServer` staging line above. Hit one router; watch the log for the DNS challenge.
   A staging certificate is issued by an untrusted "(STAGING)" CA: that is the expected result.
2. Delete `acme-lan.json` in the volume, remove the `caServer` line, restart Traefik, hit a router again. The
   production certificate is issued. Do this **at least a few days before the event** and while the host has
   internet; production has rate limits, which is why staging comes first.
3. Verify from a client in the venue:

```bash
echo | openssl s_client -connect app.<LAN_DOMAIN>:443 -servername app.<LAN_DOMAIN> 2>/dev/null \
  | openssl x509 -noout -issuer -subject -dates
```

The issuer is Let's Encrypt, and `notAfter` is about 90 days away. Traefik renews 30 days before expiry; without
internet at the venue the renewal simply fails and the old certificate keeps working until it expires.

## If the zone is at a different provider than the one you can automate

DNS-01 follows a `CNAME` on `_acme-challenge.<LAN_DOMAIN>`. Create that record once in the zone you cannot
automate, pointing to a name in a zone you can (Cloudflare or Hetzner), and let Traefik write the TXT record
there. This also allows one resolver to serve domains that live at both providers.

## Security notes

- `acme-lan.json` contains the certificate's **private key**. It lives in a Docker volume, is part of no
  backup that leaves the host unencrypted, and is never committed.
- Issued certificates are recorded in public Certificate Transparency logs. The names `*.<LAN_DOMAIN>` and the
  zone become public; do not put anything sensitive in a host name.
- The DNS token can change every record in its zone: scope it to the one zone, keep it root-only, and rotate it
  if the host is ever lost.

## Checklist

- [ ] `<LAN_DOMAIN>` resolves to `<VENUE_SERVER_IP>` from a phone on the venue Wi-Fi through the LanCache DNS and the router
- [x] Token created with the minimal scope, stored root-only, passed as a `*_FILE` variable (Cloudflare, verified)
- [x] Staging certificate issued (log shows the DNS challenge) (Cloudflare, verified)
- [ ] Production certificate issued
- [ ] `openssl s_client` shows a Let's Encrypt issuer and the expected `notAfter` from the venue network
- [ ] A phone with a clean profile opens `https://app.<LAN_DOMAIN>` without a warning
- [ ] Certificate valid at least until the end of the event, backup of `acme-lan.json` handled

## What LANoMAT automation would need

| Step                        | Existing building block                   | Gap                                                                                         |
| --------------------------- | ----------------------------------------- | ------------------------------------------------------------------------------------------- |
| Venue DNS records           | none                                      | a `DnsClient` contract (Cloudflare and Hetzner implementations, plus a fake) if wanted      |
| Token storage               | `Hosts` stores secrets encrypted          | a place for the DNS token (not in `.env` committed anywhere)                                |
| Traefik resolver and labels | `Provisioning` steps (`ProvisioningStep`) | a step that writes the resolver config and verifies the certificate with a `verify()` probe |
| Expiry watch                | `Preflight` probes                        | a probe that reads the served certificate's `notAfter` and warns below ~10 days             |
