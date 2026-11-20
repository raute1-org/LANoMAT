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
docker build -t lanomat-docs .
docker run --rm -p 8080:80 lanomat-docs
# http://localhost:8080/docs/
```

- `Dockerfile` — Node build stage, `nginx:1.27-alpine` runtime, healthcheck on `/docs/`.
- `nginx.conf` — SPA fallback only under `/docs`, immutable caching for hashed assets, one-week
  cache for screenshots, gzip, security headers.
- `deploy/compose.docs.yml` — a standalone compose service with Traefik labels.
- `deploy/traefik.docs.yml` — the router/service labels and the dynamic-config equivalent.
- `deploy/coolify.md` — the Coolify resource, domain and verification steps.

The repository's `compose.yml` is deliberately unchanged. The docs are optional infrastructure and
deploy independently of the app.

### Coolify in one paragraph

New resource → Dockerfile → base directory `docs-site`, port `80`, health path `/docs/`, domain
`https://lan.example/docs`, strip prefix **off**. Traefik routes `Host(lan.example) &&
PathPrefix(/docs)` with priority 100; the app keeps the lower-priority host router.

### Traefik in one paragraph

Use `deploy/traefik.docs.yml`: `PathPrefix(`/docs`)` with `priority=100`, `entrypoints=websecure`,
`tls.certresolver=letsencrypt`, service port 80. Never strip the prefix.

## Checks

| Command | Checks |
|---|---|
| `npm run build` | Zudoku build, MDX, OpenAPI, all routes |
| `npm run check:links` | Internal links, images and nav entries resolve |
| `docker build .` | The production image, including the healthcheck |

The docs are English (repo convention); product nouns stay German where the UI uses them
(Teilnehmer, Orga, Spielserver, Anmeldung).
