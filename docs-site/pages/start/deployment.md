---
title: Deployment
description: Deploy LANoMAT and the docs container next to it — Coolify, Traefik and /docs.
sidebar_icon: server
---

LANoMAT ships as one application stack (app, queue, scheduler, reverb-prod) and, optionally, this
documentation site as a **second static container**. The docs container is independent: its own
image, its own lifecycle, no database, no secrets. It is served at `/docs` on the same host as the
app.

<div class="live">LIVE 17:42 · app healthy · docs healthy</div>

## What gets deployed

| Service | Image | Port | Purpose |
|---|---|---|---|
| `app` | `docker/Dockerfile` | 80 | FrankenPHP, HTTP, Inertia + `/admin` |
| `queue` | same image | — | `php artisan queue:work` |
| `scheduler` | same image | — | `php artisan schedule:work` |
| `reverb-prod` | same image | 8080 | websockets |
| `docs` | `docs-site/Dockerfile` | 8080 | static docs at `/docs` (non-root) |

**Build all four application services.** Building only `app` leaves queue, scheduler and reverb on
an old image, and breaks the version match for the desktop client download. `scripts/deploy/deploy.sh`
does it correctly and exports `APP_VERSION`/`APP_COMMIT`.

## App deployment (short version)

1. Configure `.env` for production: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`,
   `APP_DISPLAY_TIMEZONE=Europe/Berlin`, `REVERB_SCHEME=https`, and
   `REVERB_ALLOWED_ORIGINS=lan.example` (**host name only, no scheme**).
2. Build and start: `scripts/deploy/deploy.sh` or `docker compose --profile prod up -d`.
3. Migrate and create the admin: `php artisan lanomat:install --admin-discord-id=<id>`.
4. Point Traefik at the app and reverb services; terminate TLS at the edge.

Full production detail, including the Discord guild gate, backups and the CSP, lives in the
repository's `docs/deploy-lan-raute1.md`; the venue/offline variant is in
`docs/infra/app-at-the-venue.md`.

## Docs container

The docs image is a two-stage build: Node builds the Zudoku site, unprivileged nginx serves the
static result as uid 101 on **port 8080** (not 80). No database, no secrets, no volume.

```bash
cd docs-site
docker build -t lanomat-docs .
docker run --rm -p 4015:8080 lanomat-docs
# open http://localhost:4015/docs/
```

`basePath` is `/docs`, so the build writes into `dist/docs/`. nginx serves `dist` as its root and
resolves extensionless deep links to their prerendered `.html` file (`/docs/api` →
`/docs/api.html`). There is **no SPA catch-all**: an unknown path is a real `404` with the docs'
own not-found page. The container answers `GET /docs/` with the introduction page itself (real
HTML, no JavaScript redirect), `/` redirects to `/docs/`, and `/docs/games` redirects to
`/docs/system/games-library`. The document root is read-only at runtime, so the service also runs
with `--read-only --tmpfs /tmp`.

### Coolify

Create a **new resource → Dockerfile** for the docs:

| Setting | Value |
|---|---|
| Repository | the LANoMAT repository |
| Branch | `main` (or the release branch you deploy) |
| Dockerfile | `docs-site/Dockerfile` |
| Build context | `docs-site` |
| Port | `8080` (non-root nginx) |
| Domain | `https://lan.example/docs` |
| Health check | `GET /docs/` |
| Persistent storage | none |

The app stays its own Coolify resource on `https://lan.example`. Two resources, one host, one TLS
certificate. Coolify's Traefik picks the `/docs` path route by the domain path you enter.

A ready-to-copy standalone service definition is in
[`docs-site/deploy/compose.docs.yml`](https://github.com/raute1-org/LANoMAT/blob/main/docs-site/deploy/compose.docs.yml)
and the manual Traefik labels are in `docs-site/deploy/traefik.docs.yml`. The repository's
`compose.yml` also carries the docs as an optional `docs` profile (read-only, `cap_drop: ALL`,
loopback preview on `127.0.0.1:4015`):

```bash
# Preview without Traefik:
docker compose --profile docs up -d docs
curl -I http://127.0.0.1:4015/docs/

# Next to the app, behind Traefik (the docs router needs the app's network):
docker compose --profile prod --profile docs up -d traefik docs
```


### Traefik labels

If you run Traefik yourself, route `/docs` to the docs container with a higher priority than the
app's catch-all router:

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.lanomat-docs.rule=Host(`lan.example`) && PathPrefix(`/docs`)"
  - "traefik.http.routers.lanomat-docs.priority=100"
  - "traefik.http.routers.lanomat-docs.entrypoints=websecure"
  - "traefik.http.routers.lanomat-docs.tls.certresolver=letsencrypt"
  - "traefik.http.services.lanomat-docs.loadbalancer.server.port=8080"
```

The app router must keep a lower priority for the same host. Do not strip the `/docs` prefix:
Zudoku's assets, links and the OpenAPI reference all live under it. The docs router deliberately
does not use the app's `secure-headers@file` middleware: the container sets its own headers and the
middleware would add a second `X-Frame-Options` with a different value.

### Cache and headers

`docs-site/nginx.conf`:

- gzip for HTML, CSS, JS, JSON, YAML, Markdown, SVG and fonts;
- immutable, one-year caching for hashed `assets/` (`max-age=31536000, immutable`);
- one-hour caching for the unhashed screenshots (`/docs/screens/`). Their names are stable, so they
  cannot be immutable without a build-time hash; one hour keeps a deploy visible;
- `no-cache` for HTML and `404` so a deploy is visible immediately, one hour for `.md`, `llms.txt`
  and the OpenAPI YAML;
- no SPA catch-all: an unknown path under `/docs` is a real `404` with the docs' not-found page,
  and files outside `/docs` 404 too;
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy:
  strict-origin-when-cross-origin`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`,
  `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`.

No `Content-Security-Policy` yet: Zudoku ships an inline theme-boot script and inline styles, so a
CSP needs `'unsafe-inline'` (or nonces in the prerendered HTML) and a report-only rollout. The HSTS
value matches the app's middleware, so a `/docs` response never weakens the host's policy.

## Updating the docs

The docs are built from the repository, not hand-edited in production. A deploy:

1. checks out the same commit as the app;
2. runs `npm ci && npm run screenshots && npm run build` in `docs-site/`;
3. builds the image and replaces the container.

The screenshot copy is committed, so `npm ci && npm run build` alone also produces a complete site.
`npm run screenshots` is only needed after the app's `docs/screenshots/` changed.

## Verifying a deployment

```bash
BASE_URL=https://lan.example docs-site/deploy/verify.sh   # 30+ checks
curl -sI https://lan.example/docs/ | head -n 3             # 200, text/html
curl -s  https://lan.example/docs/api | grep -o 'LANoMAT API'
curl -sI https://lan.example/docs/screens/home-dark.png | grep -i cache-control  # max-age=3600
curl -sI https://lan.example/docs/games | grep -i location                       # -> /docs/system/games-library
curl -sI https://lan.example/docs/nope | head -n 1                               # 404
```

Expect `200` for `/docs/` (the introduction, not an empty JS redirect), the OpenAPI title in the API
page, and `max-age=3600` for the screenshot. If `/docs` returns the app instead, the Traefik
priority is wrong; if assets 404, the `/docs` prefix was stripped.
