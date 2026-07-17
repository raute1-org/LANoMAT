# Traefik v3 ingress + TLS (M7, roadmap 7.1)

This adds [Traefik v3](https://doc.traefik.io/traefik/) as the reverse
proxy in front of the M5.6 `prod` compose profile: it terminates TLS and
routes public traffic to the `app` service (participant UI + Filament
`/admin`, same FrankenPHP service, path-routed) and to `reverb-prod`
(WebSockets/broadcasting). It runs **only** under `--profile prod` — the
dev stack (`docker compose up -d`, no `--profile` flag) does not start
Traefik at all and is unaffected.

Config lives in `docker/traefik/traefik.yml` (static: entrypoints, the
Docker provider, the ACME certificate resolver) and
`docker/traefik/dynamic.yml` (TLS options + a reusable `secure-headers`
middleware), loaded by the `traefik` service in `compose.yml`. Per-router
rules live as labels directly on `app` and `reverb-prod`.

This document only produces and validates the **config** (`docker compose
--profile prod config`). A real ACME certificate against a real public
domain is real-infra verification, deferred to when M7's actual hosts
exist (see `docs/prod-test.md` for the equivalent one-time walkthrough
model used for M5.6/Discord).

## Verified Traefik v3 facts (context7, `/traefik/traefik`, 2026-07-17)

- Static config (`traefik.yml`) shape: `entryPoints.web` (`:80`),
  `entryPoints.websecure` (`:443`, `http.tls: {}`), `providers.docker`
  (`endpoint`, `exposedByDefault: false`, `network`), `providers.file`
  (loads `dynamic.yml`), `certificatesResolvers.<name>.acme` (`storage`,
  `httpChallenge.entryPoint` or `tlsChallenge`).
- Docker labels (v3 syntax, **not** v2's `traefik.frontend.*`):
  `traefik.enable`, `traefik.http.routers.<name>.rule`,
  `.entrypoints`, `.tls`, `.tls.certresolver`, `.middlewares`,
  `traefik.http.services.<name>.loadbalancer.server.port`.
- **Traefik's static-config file does not support `${VAR}` interpolation**
  — that mechanism only exists for a separate env-var-prefixed
  install-config path (`TRAEFIK_*` vars), and the docs are explicit that
  mixing file config with env-var config for the same value is
  unsupported. This is a deviation worth flagging explicitly: an earlier
  draft of `traefik.yml` in this task tried `email: '${ACME_EMAIL}'`
  directly in the file, which would silently **not** substitute. The fix:
  `traefik.yml`'s `certificatesResolvers.letsencrypt.acme` omits `email`
  entirely, and the `traefik` compose service instead passes
  `--certificatesresolvers.letsencrypt.acme.email=${ACME_EMAIL}` via
  `command:` — there, `${ACME_EMAIL}` is Compose's own interpolation
  (resolved from `.env` before Traefik ever starts), not Traefik's.
- **WebSocket upgrade is automatic** — Traefik v3 detects and forwards the
  `Upgrade`/`Connection` handshake for any plain HTTP router; no special
  middleware, flag, or "WS mode" exists to turn on. A normal router +
  service definition (as used for `reverb-prod` below) is sufficient.
- A router with `tls: {}` (TLS enabled) but **no** `tls.certresolver` label
  falls back to Traefik's automatically-generated self-signed default
  certificate — this is the documented pure-LAN fallback used below.

## Routing scheme

| Router    | Rule                          | Backend        | Port | TLS                          |
|-----------|-------------------------------|----------------|------|-------------------------------|
| `app`     | `Host(\`${APP_DOMAIN}\`)`      | `app`          | 80   | `certresolver=letsencrypt`   |
| `reverb`  | `Host(\`ws.${APP_DOMAIN}\`)`   | `reverb-prod`  | 8080 | `certresolver=letsencrypt`   |

- **`app` covers both the participant UI and Filament `/admin`.** They are
  the same FrankenPHP service and the same Laravel router (`/admin` is
  just a path prefix inside that one app) — there is deliberately **one**
  Traefik router here, not two. Path-level access control for `/admin`
  stays exactly what it already is (Filament's own panel auth + the
  existing role Gate), unchanged by this task.
- **`reverb-prod` gets its own router on a `ws.` subdomain**, not a shared
  path on the `app` Host rule. A shared-path scheme (e.g.
  `Host(APP_DOMAIN) && PathPrefix('/app')`) would need Traefik's
  `stripprefix` middleware to reach Reverb's own HTTP surface cleanly,
  since Reverb (unlike Laravel) does not itself understand being served
  under a path prefix — a subdomain avoids that rewriting entirely and
  keeps the two routers fully independent. `ws.${APP_DOMAIN}` is derived
  automatically from `APP_DOMAIN` in the label (no separate env var).
  Point `REVERB_HOST=ws.<your-domain>`, `REVERB_PORT=443`,
  `REVERB_SCHEME=https` at this router (see `.env.example`'s Reverb
  section and `docs/prod-test.md`).
- **Reverb WS caveat:** confirm `REVERB_ALLOWED_ORIGINS` in `.env` is
  locked to the real origin(s) (`https://${APP_DOMAIN}`), not `*` — see
  `config/reverb.php` and the existing `.env.example` comment. This was
  already true before Traefik; Traefik does not change it, just terminates
  the TLS in front of it.
- All public HTTP is redirected to HTTPS at the `web` (`:80`) entrypoint
  (`entryPoints.web.http.redirections`, see `traefik.yml`) — there is no
  plain-HTTP path to either router once Traefik is in front.

## TLS: ACME vs. self-signed/internal-CA (pure LAN)

**Public domain (ACME / Let's Encrypt):**

1. Point DNS `A`/`AAAA` records for `APP_DOMAIN` (and `ws.APP_DOMAIN`) at
   the host running the `prod` profile.
2. Set `APP_DOMAIN` and `ACME_EMAIL` in `.env`.
3. Ensure port 80 is reachable from the public internet on that host — the
   `letsencrypt` resolver uses the HTTP-01 challenge
   (`certificatesResolvers.letsencrypt.acme.httpChallenge.entryPoint: web`
   in `traefik.yml`), which requires exactly that.
4. `docker compose --profile prod up -d` — Traefik requests and renews
   certificates automatically, persisted in the `traefik_acme` named
   volume (`/letsencrypt/acme.json` inside the container) so restarts
   don't re-request them.

**Pure LAN, no public DNS (a LAN party with no internet-routable domain):**

ACME's HTTP-01 challenge is unreachable from Let's Encrypt's servers in
this case, so it cannot be used. Two options, both already supported by
this config without changes:

- **Do nothing (simplest):** leave `APP_DOMAIN`/`ACME_EMAIL` unset. No
  router's `Host()` rule ever matches (an empty `APP_DOMAIN` makes
  `` Host(``) `` match nothing), so **add a LAN-only fallback router** with
  a rule that always matches, e.g. `traefik.http.routers.app.rule:
  PathPrefix(\`/\`)` instead of `Host(...)`, and drop the `certresolver`
  label. Traefik then serves its automatically-generated self-signed
  default certificate on `websecure` to any client connecting by IP or a
  LAN-internal hostname; browsers show a "not trusted" warning that
  participants click through once (acceptable for a one-weekend LAN
  party). This requires editing the `app`/`reverb` router `rule` labels in
  `compose.yml` for the specific LAN deployment — documented here rather
  than baked in, since the public-domain path is the default expected by
  the existing `.env.example`/`docs/prod-test.md` flow.
- **Internal CA:** stand up a small internal CA (e.g. `step-ca` or a
  self-signed root imported into each participant device's trust store
  ahead of time) and mount the issued cert/key into Traefik via
  `dynamic.yml`'s `tls.stores.default.defaultCertificate` (`certFile`/
  `keyFile`), instead of relying on the ACME resolver at all. This avoids
  the browser warning but needs the CA root pre-distributed to attendees'
  devices — more setup, cleaner result. Worth it for a recurring LAN
  series with the same attendee base; overkill for a one-off event.

Either LAN path leaves the `letsencrypt` resolver and ACME-labeled routers
untouched — they simply never trigger without `APP_DOMAIN`/`ACME_EMAIL`
set, so a deployment can start on the self-signed fallback and switch to a
real domain + ACME later without reworking `traefik.yml`.

## Composing with the M5.6 prod profile

Nothing about the M5.6 `prod` profile's existing services (`app`, `queue`,
`scheduler`, `reverb-prod`) changes behaviorally — they still build from
`docker/Dockerfile`, still depend on `postgres`/`redis`, still read
`env_file: .env`. This task only:

- adds the `traefik` service (`profiles: [prod]`, same convention as
  `app`/`queue`/`scheduler`/`reverb-prod`),
- adds `traefik.*` router/service labels to `app` and `reverb-prod` (no
  behavior change for `queue`/`scheduler` — they aren't routed to),
  and
- keeps `app`'s/`reverb-prod`'s existing `ports:` publishes
  (`8000:80`/`8081:8080`) as-is, for direct host access during debugging —
  Traefik does not require them removed, it just adds a second, public,
  TLS-terminated path to the same containers on 80/443.

Once real infra exists, `docs/prod-test.md`'s one-time walkthrough gets a
Traefik-fronted `APP_URL=https://${APP_DOMAIN}` instead of a
Cloudflare/ngrok tunnel — the tunnel workaround it currently documents was
an explicit stand-in for "TLS is M7", which this task delivers the config
half of.

## Validating this config

```bash
docker compose --profile prod config   # prod stack, Traefik included
docker compose config                  # dev stack, no --profile — unchanged
```

Both must parse without error. Neither requires real DNS, a real ACME
account, or Docker actually pulling images — `config` only resolves and
renders the merged Compose YAML.
