# LANoMAT docs site

The documentation for LANoMAT, built with **Zudoku 0.94** (React 19) and served as a static
container at **`/docs`** on the same host as the app.

- Product docs: modules, screenshots, game recipes, MCP, pi agent, API reference, operations.
- Brand: the Signalpult system from `docs/design.md` and `docs/brand/` — graphite/paper, one signal
  amber, Space Grotesk + JetBrains Mono, 6 px radius, the `● LIVE 17:42` motif.
- Base path: `/docs` (`basePath` in `zudoku.config.tsx`). The build writes `dist/docs/`.

## Develop

```bash
cd docs-site
npm ci
npm run dev        # http://localhost:3000/docs/
```

Node 22.22+ is required by Zudoku 0.94 (the `node:22-alpine` image used here is
22.23).

## Build

```bash
npm ci
npm run build      # static output in dist/docs/
npm run check:links
```

`npm ci && npm run build` is the contract; the lockfile is committed and no network is needed at
runtime. The Docker image does exactly this.

## Content map

| Path | What |
|---|---|
| `pages/start/` | Introduction, quickstart, deployment, screen tour |
| `pages/system/` | Overview, 5 group pages, 34 module pages |
| `pages/recipes/` | How recipes work, UT2004/UT2004VR, Ridge Racer, contributing, SMB |
| `pages/mcp/` | Server & auth, tools/resources, vibecoding, security |
| `pages/agent/` | pi agent overview, install, models & fuel, troubleshooting |
| `pages/reference/` | Commands, environment, troubleshooting, changelog |
| `apis/lanomat.openapi.yaml` | Hand-written OpenAPI 3.1 for the REST + MCP surface |
| `public/screens/` | Copied screenshots (light/dark pairs + native client) |
| `public/logo-*.svg`, `favicon.*` | Brand assets from `public/` at the repo root |

Every page has frontmatter, real content and links that `npm run check:links` verifies.

## Screenshots

The app owns the screenshots (`docs/screenshots/`). Copy them into the docs:

```bash
npm run screenshots
```

This runs `scripts/copy-screenshots.mjs` and copies the whole tree into `public/screens/`
(light/dark pairs, the native client walkthrough, empty/error states). Commit the result: the docs
build must not depend on the app's working tree.

Reference them as root-absolute `/screens/<name>.png`. Zudoku rewrites the URL to
`/docs/screens/<name>.png` in the built output, which is exactly where nginx serves them under the
base path. Do not write `/docs/screens/...` by hand; the extra prefix would be applied twice.

For side-by-side light/dark, use a two-column Markdown table (see `pages/start/tour.md`); a caption
line in `shot-caption` mono follows.

## Theme

`zudoku.config.tsx` sets `theme.light` / `theme.dark` from the Signalpult tokens:

| Role | Light | Dark |
|---|---|---|
| Background | `#F7F8FA` | `#0F1215` |
| Card | `#FFFFFF` | `#171B20` |
| Primary | `#A85A00` | `#FFB020` |
| Foreground | `#14181C` | `#E7ECEF` |
| Muted foreground | `#5B646E` | `#8A949E` |
| Border | `#E1E5EA` | `#262C33` |
| Live | `#E8930A` | `#FFB020` |
| Radius | `0.375rem` | `0.375rem` |

`styles.css` loads Space Grotesk and JetBrains Mono, and adds the brand layer: the live dot, the
hero, feature cards, screenshot framing and the `status-ok/warn/down` variants. Colours live in the
config and CSS, never in page content.

The logo is the node-L mark: `public/logo-mark.svg` (light) and `logo-mark-dark.svg` (dark), copied
from the app's canonical `public/logo-mark.svg`; `favicon.svg`, `favicon.ico` and
`apple-touch-icon.png` come from the app too.

## Deploy

```bash
docker build -t lanomat-docs docs-site
docker run --rm -p 4015:8080 lanomat-docs
# http://localhost:4015/docs/
```

The image is a **non-root** static server: `nginx-unprivileged` (uid 101) on **port 8080**, no
Node, no npm and no source in the final layer, source maps and build-only output pruned — about
**45 MB** `docker image ls` size. The document root is never written at runtime, so
`--read-only --tmpfs /tmp` is supported (the compose service uses it). Running it without those
flags works too.

| File | What |
|---|---|
| `Dockerfile` | Node 22 build stage (`npm ci && npm run build`), unprivileged nginx runtime, `HEALTHCHECK` on `/docs/` |
| `nginx.conf` | canonical `/docs` base path, real 404s (no SPA catch-all), immutable hashed assets, gzip, security headers |
| `deploy/nginx-security-headers.conf` | the header set, included at server level and in the assets location |
| `deploy/healthcheck.sh` | the Docker healthcheck; BusyBox `wget`, no curl |
| `deploy/verify.sh` | the curl matrix (`BASE_URL=... deploy/verify.sh`) |
| `deploy/compose.docs.yml` | standalone compose service with Traefik labels |
| `deploy/traefik.docs.yml` | the router/service labels and the dynamic-config equivalent |
| `deploy/coolify.md` | the Coolify resource, path-prefix vs subdomain, verification and troubleshooting |

### URL contract

The container always serves the `/docs` prefix; Traefik must **not** strip it. Zudoku's `basePath`
is `/docs`, so every asset and link in the built HTML is absolute under `/docs`.

| Request | Answer |
|---|---|
| `/docs/` | `200`, entry page (client-side redirect to the intro) |
| `/docs` | `301 → /docs/` (path-relative, no `:8080` leaked) |
| `/` | `302 → /docs/` (also makes a docs subdomain work) |
| `/docs/api`, `/docs/recipes/ut2004` | `200` (prerendered `.html` behind the extensionless link) |
| `/docs/nope`, `/docs/assets/nope.js`, `/nope` | `404` with the docs' own not-found page |
| `/docs/assets/<hashed>` | `200`, `Cache-Control: public, max-age=31536000, immutable` |
| `/docs/screens/*.png` | `200`, one-day cache |
| `*.html`, `.md`, `llms.txt`, OpenAPI `.yaml` | revalidate / one hour |

Headers on every response: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
`Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Strict-Transport-Security`; no CSP yet (the
inline theme-boot script needs a planned rollout, same decision as the app). gzip is on for text,
CSS, JS, JSON, YAML, Markdown, SVG and fonts.

### Compose and Traefik

The repository's `compose.yml` has the docs as an optional profile, next to the app:

```bash
docker compose --profile docs up -d docs     # https://${APP_DOMAIN}/docs
```

The service builds `./docs-site`, publishes nothing to the host, runs read-only with
`cap_drop: [ALL]` and routes through Traefik with these labels:

```yaml
traefik.enable: "true"
traefik.http.routers.docs.rule: "Host(`${APP_DOMAIN}`) && PathPrefix(`/docs`)"
traefik.http.routers.docs.priority: "100"
traefik.http.routers.docs.entrypoints: "websecure"
traefik.http.routers.docs.tls: "true"
traefik.http.routers.docs.tls.certresolver: "letsencrypt"
traefik.http.services.docs.loadbalancer.server.port: "8080"
traefik.http.services.docs.loadbalancer.healthcheck.path: "/docs/"
```

No `StripPrefix` and no `secure-headers@file` middleware: the container sets its own headers, and
the app middleware would add a conflicting `X-Frame-Options: DENY` next to the docs' `SAMEORIGIN`.
For a subdomain, use `Host(docs.example.com)` instead of the path matcher — `/` then redirects to
`/docs/`. See `deploy/traefik.docs.yml` and `deploy/coolify.md`.

### Coolify in one paragraph

New resource → Dockerfile → base directory `docs-site`, port **8080**, health path `/docs/`, domain
`https://lan.example/docs`, strip prefix **off**. Traefik routes `Host(lan.example) &&
PathPrefix(/docs)` with priority 100; the app keeps the lower-priority host router. The alternative
is a `docs.example.com` resource without a path matcher — the app stays untouched either way.

### Authoring links

Markdown links may be written as `/start/quickstart` or `/docs/start/quickstart`; `check:links`
normalises both. Raw HTML anchors (`<a href="...">` inside MDX) must **not** include the prefix —
write `/system/overview`. The MDX pipeline prefixes `basePath` to raw anchors, so
`href="/docs/system/overview"` becomes `/docs/docs/system/overview` and 404s.

## Checks

| Command | Checks |
|---|---|
| `npm run build` | Zudoku build, MDX, OpenAPI, all routes |
| `npm run check:links` | Internal links, images and nav entries resolve |
| `docker build .` | The production image, including `nginx -t` and the healthcheck |
| `deploy/verify.sh` | The deployed URL: status matrix, caching, gzip, security headers |

The docs are English (repo convention); product nouns stay German where the UI uses them
(Teilnehmer, Orga, Spielserver, Anmeldung).
