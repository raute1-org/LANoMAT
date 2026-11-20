# Deploying the docs on Coolify

The docs are a second, static container next to the LANoMAT app. They need no database, no Redis,
no secrets and no persistent volume. The only contract with the app is the URL: **`/docs` on the same
host**.

## 1. Create the resource

In Coolify, on the same project/environment as the app:

1. **New Resource → Dockerfile** (not "Docker Compose", unless you use the compose file in this
   folder).
2. Repository: the LANoMAT repository.
3. Branch: the branch you deploy (`main`, or the same release branch as the app).
4. **Base directory:** `docs-site`
5. **Dockerfile location:** `Dockerfile` (relative to the base directory).
6. **Port:** `80`.
7. **Health check path:** `/docs/`.
8. **Persistent storage:** none.
9. **Build arguments / environment:** none required.

The build runs `npm ci && npm run build`, then copies `dist/` into nginx. The output is at
`dist/docs/` because `basePath` is `/docs`.

## 2. Set the domain

| Field | Value |
|---|---|
| Domain | `https://lan.example/docs` |
| Force HTTPS | on |
| Strip prefix | **off** |

Coolify's Traefik creates a router for `Host(lan.example) && PathPrefix(/docs)` and forwards the
request unchanged. The app keeps its own router for the same host with lower priority. If requests
for `/docs` still reach the app, set the docs router priority explicitly:

```yaml
traefik.http.routers.<docs-router>.priority: "100"
```

(The label is in `traefik.docs.yml`; in Coolify use **Labels** on the resource.)

## 3. Verify

```bash
curl -sI https://lan.example/docs/ | head -n 3        # 200, text/html
curl -s  https://lan.example/docs/api | grep LANoMAT  # API page
curl -sI https://lan.example/docs/screens/home-dark.png | grep -i cache-control
curl -sI https://lan.example/docs/start/deployment    # 200
curl -sI https://lan.example/docs/nope                # 200 (SPA fallback)
```

`/docs/nope` returning the docs shell is expected for client-side routes; a missing screenshot or
asset must be `404`, not `index.html`.

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
| `/` redirects to `/docs/` but the app is at `/` | The docs container is also the host catch-all; restrict its router to `PathPrefix(/docs)` |
| Build fails on `npm ci` | `package-lock.json` must be committed; do not use `npm install` in the image |
| Screenshots 404 | `public/screens` missing in the build context; run `npm run screenshots` |
| Old content after deploy | HTML is no-cache, assets are hashed; hard reload |
