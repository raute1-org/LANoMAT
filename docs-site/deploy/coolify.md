# Deploying the docs on Coolify

The docs are a second, static container next to the LANoMAT app. They need no database, no Redis,
no secrets and no persistent volume. The only contract with the app is the URL: **`/docs` on the same
host**.

The image runs nginx as **uid 101 (non-root)** on port **8080**, is safe to run with a read-only
root filesystem (only `/tmp` is written) and carries its own healthcheck.

## 1. Create the resource

In Coolify, on the same project/environment as the app:

1. **New Resource → Dockerfile** (not "Docker Compose", unless you use `deploy/compose.docs.yml`).
2. Repository: the LANoMAT repository.
3. Branch: the branch you deploy (`main`, or the same release branch as the app).
4. **Base directory:** `docs-site`
5. **Dockerfile location:** `Dockerfile` (relative to the base directory).
6. **Port (exposed):** `8080` — not 80; the image is the unprivileged nginx variant.
7. **Health check path:** `/docs/` — the image also has a `HEALTHCHECK` that probes it with BusyBox
   `wget` (no curl in the image).
8. **Persistent storage:** none.
9. **Build arguments / environment:** none required. `APP_VERSION` is optional and only sets an
   OCI label.

The build runs `npm ci && npm run build`, prunes source maps and copies only `dist/` into nginx.
The output is at `dist/docs/` because `basePath` is `/docs`.

## 2. Path prefix or subdomain?

Both work with the same image and the same labels; the difference is only the Traefik rule.

### Option A — path prefix on the app host (recommended)

| Field | Value |
|---|---|
| Domain | `https://lan.example/docs` |
| Force HTTPS | on |
| Strip prefix | **off** |

Coolify's Traefik creates a router for `Host(lan.example) && PathPrefix(/docs)` and forwards the
request unchanged. The app keeps its own router for the same host with a lower priority. If requests
for `/docs` still reach the app, set the docs router priority explicitly:

```yaml
traefik.http.routers.<docs-router>.priority: "100"
```

(The label is in `traefik.docs.yml`; in Coolify use **Labels** on the resource.)

Why the prefix must not be stripped: Zudoku's `basePath` is `/docs`, so every link and asset URL in
the built HTML is absolute under `/docs`. With StripPrefix on, `/docs/assets/...` reaches nginx as
`/assets/...` and 404s.

### Option B — own subdomain

| Field | Value |
|---|---|
| Domain | `https://docs.example.com` |
| Force HTTPS | on |
| Strip prefix | **off** |

Set the router rule to `Host(docs.example.com)` without a path matcher. The container still serves
its canonical base path: `https://docs.example.com/` answers `302 → /docs/`, and every page lives
under `/docs/...`. That is intentional — the prerendered HTML has absolute `/docs/...` URLs, so a
true root deployment would need a rebuild with `basePath: "/"`. Keep StripPrefix off here too.

Choose the subdomain if you want a separate certificate/host or to hand the docs to another team;
choose the path if you want one origin, one certificate and links straight from the app.

## 3. Verify

```bash
BASE_URL=https://lan.example docs-site/deploy/verify.sh
```

The script is the same matrix the deployment card proves: `/docs/`, `/docs/api`,
`/docs/recipes/ut2004`, deep links, an unknown path (404), immutable hashed assets, gzip,
security headers and the OpenAPI YAML download. For a local run without Coolify:

```bash
docker build -t lanomat-docs docs-site
docker run -d --name lanomat-docs --read-only --tmpfs /tmp -p 4015:8080 lanomat-docs
BASE_URL=http://127.0.0.1:4015 docs-site/deploy/verify.sh
docker inspect --format '{{.State.Health.Status}}' lanomat-docs   # healthy
```

Manual spot checks:

```bash
curl -sI https://lan.example/docs/ | head -n 3                 # 200, text/html
curl -sI https://lan.example/docs                              # 301 -> /docs/
curl -s  https://lan.example/docs/api | grep -i lanomat         # API page
curl -sI https://lan.example/docs/screens/home-dark.png | grep -i cache-control
curl -sI https://lan.example/docs/assets/<hashed>.css | grep -i cache-control  # immutable
curl -sI https://lan.example/docs/start/deployment             # 200
curl -sI https://lan.example/docs/nope                         # 404 (prerendered not-found page)
curl -sI https://lan.example/docs/assets/nope.js               # 404, never the shell
```

`/docs/nope` is a real **404** (nginx has no SPA catch-all: every route is prerendered). A missing
screenshot or asset is a 404 too, never `index.html`.

## 4. Updating

Two options:

- **Redeploy on push:** enable Coolify's automatic deployment for the docs resource on the same
  branch. A docs change does not rebuild the app.
- **Deploy together:** add a post-deployment step to the app that triggers the docs webhook. The
  docs must be built from the same commit as the app; a stale docs site is worse than none.

The screenshot copy is committed, so a fresh `npm ci && npm run build` is complete. Run
`npm run screenshots` only after the app's `docs/screenshots/` changed, and commit the result.

## 5. Rollback

The docs are stateless. Roll back by redeploying the previous commit; the old image is cached by
Coolify. Nothing in the app depends on the docs being present, so a failed docs deploy never takes
the LAN down.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `/docs` shows the app | Router priority; set the docs router to 100 |
| Assets 404 | Prefix stripping is on; turn it off |
| `/` redirects to `/docs/` but the app is at `/` | The docs container is also the host catch-all; restrict its router to `PathPrefix(/docs)` (Option A) or `Host(docs.example.com)` (Option B) |
| Container unhealthy, port 80 | The image listens on **8080** (non-root); point Coolify at 8080 |
| Redirects point at `:8080` | An old image with absolute redirects; rebuild — `absolute_redirect off` makes them path-relative |
| Build fails on `npm ci` | `package-lock.json` must be committed; do not use `npm install` in the image |
| Screenshots 404 | `public/screens` missing in the build context; run `npm run screenshots` |
| Old content after deploy | HTML is no-cache, assets are hashed; hard reload |
