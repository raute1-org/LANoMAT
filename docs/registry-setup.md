# Own Docker registry (M7, roadmap 7.2)

A private container registry for two kinds of images:

- **The LANoMAT `app` image** — the FrankenPHP image built from
  `docker/Dockerfile` (M5.6), used by `compose.yml`'s `prod` profile
  (`app`/`queue`/`scheduler`/`reverb-prod` all share it).
- **Gameserver images / Pelican eggs** (M6.1) — the container images
  referenced by Pelican eggs and by `7.4`'s custom-Docker path
  (`app/Modules/CustomServers/`), so a LAN with flaky/slow internet doesn't
  re-pull public images from Docker Hub/GHCR for every server start.

This document only produces and validates **config + CI + docs** — an
actual registry container/host, a real push, and a real prod pull are
real-infra verification, deferred like M6-T13/M7-T8's Traefik ACME cert
(see roadmap M7's "Deferred / explicitly out of scope").

## Registry choice: `registry:2` (the official Distribution image)

No hosted third-party registry is required — the [official OCI
Distribution reference implementation](https://hub.docker.com/_/registry)
(`registry:2`) is first-party-adjacent (Docker's own image), simple to run
as a single container, and enough for a private LAN-party registry. This is
**documented as an optional `registry` Compose profile**, not forced into
the default dev or prod stack (per the M7 task brief: "document, do not
force a container") — most deployments will instead point `.env`'s
`REGISTRY_*` values at an already-existing registry (a self-hosted Harbor/
GitLab registry, GHCR, or this same `registry:2` container running
elsewhere), so the CI workflow and prod-pull docs below work unchanged
either way.

If you do want to run the registry alongside LANoMAT itself, add this to
`compose.yml` under a dedicated `registry` profile (not part of `prod`, so
`docker compose --profile prod up` never depends on it — it is
infrastructure the CI runner or prod host talks to, not a runtime
dependency of the app itself):

```yaml
# Optional, self-hosted alternative to an external registry — NOT started
# by `docker compose --profile prod up`. Bring it up explicitly with
# `docker compose --profile registry up -d` on whichever host is meant to
# serve as the registry (can be the same host as `prod`, or a separate one).
registry:
  image: registry:2
  profiles: [registry]
  environment:
    REGISTRY_AUTH: htpasswd
    REGISTRY_AUTH_HTPASSWD_REALM: LANoMAT Registry
    REGISTRY_AUTH_HTPASSWD_PATH: /auth/htpasswd
  ports: ['5000:5000']
  volumes:
    - registry_data:/var/lib/registry
    - ./docker/registry/auth:/auth:ro
```

Generate the htpasswd file once with `docker run --rm --entrypoint htpasswd
httpd:2 -Bbn <user> <password> > docker/registry/auth/htpasswd` (bcrypt,
`-B`, is what the registry's `htpasswd` auth backend requires — plain MD5
`htpasswd` entries are rejected). Put this registry behind Traefik (M7 Task
8) with its own `Host()` router/TLS label, the same pattern `app`/
`reverb-prod` already use in `compose.yml`, if it needs to be reachable
from more than one host on the LAN.

## Configuration: `.env` keys

These are `.env` keys / CI variables only — there is no
`config('services.registry.*')` accessor; nothing in the app reads this
config at runtime. `REGISTRY_HOST`/`REGISTRY_USERNAME`/`REGISTRY_PASSWORD`
are consumed directly by CI (`.github/workflows/publish-images.yml`) and by
the manual `docker login`/deploy commands below:

```dotenv
# Own Docker registry (M7, roadmap 7.2). REGISTRY_HOST is the registry's
# host[:port] (e.g. "registry.lan.example" or "registry.lan.example:5000"
# for the optional registry:2 container above, or "ghcr.io"/an existing
# external registry) — used as the image-tag prefix
# ("$REGISTRY_HOST/lanomat/app:...") and as docker/login-action's
# `registry` input in .github/workflows/publish-images.yml.
REGISTRY_HOST=
REGISTRY_USERNAME=
REGISTRY_PASSWORD=
```

`REGISTRY_HOST` is deliberately read as a GitHub Actions **variable**
(`vars.REGISTRY_HOST`, Settings → Secrets and variables → Actions →
**Variables**), not a secret — it's a hostname, not a credential, and using
a variable lets the `if:` guard below check it directly. `REGISTRY_USERNAME`/
`REGISTRY_PASSWORD` (or a registry access token used as the password) are
real secrets (Settings → Secrets and variables → Actions → **Secrets**).

## CI: `publish-images.yml`

`.github/workflows/publish-images.yml` builds the `app` image from
`docker/Dockerfile` and pushes it on a `v*` tag push or a published GitHub
Release, using the current stable Docker GitHub Actions
(`docker/metadata-action@v6` for tag/label derivation,
`docker/setup-buildx-action@v4`, `docker/login-action@v3`,
`docker/build-push-action@v7` — verified against the actions' own docs via
context7, 2026-07-17) — the same runner (`ubuntu-latest`) and checkout
action (`actions/checkout@v7`) `ci.yml` already uses.

**Guarded, not forced:** the job only runs when
`github.repository_owner == 'raute1-org' && vars.REGISTRY_HOST != ''` — a
fork, or this repo before `REGISTRY_HOST` is configured, sees this job
report as skipped rather than failing on a missing secret. `ci.yml` (pint/
phpstan/pest/eslint/prettier/vue-tsc/build) is unaffected either way; this
is a separate workflow file that only reacts to tags/releases, never to
`push`/`pull_request` on `main`.

Tags produced (via `docker/metadata-action`, `images:
${{ vars.REGISTRY_HOST }}/lanomat/app`):

- `<version>` and `<major>.<minor>` from the `v*` git tag (semver patterns)
- `latest`, only on a published Release (not on every `v*` tag push, so a
  pre-release/RC tag never clobbers `latest`)

A registry build-cache (`cache-from`/`cache-to` against
`.../lanomat/app:buildcache`) speeds up repeat builds without needing
GitHub Actions cache storage.

## Gameserver images / Pelican eggs

Roadmap 7.2 also covers gameserver images the Pelican eggs (M6) and the
custom-Docker path (7.4, `app/Modules/CustomServers/`) pull. Unlike the
`app` image, these aren't built by this repo's CI — they're the upstream
game/server images (or images built by the LAN's own Dockerfiles for
custom eggs). Push them to the same registry manually or via their own
pipeline:

```bash
docker login "$REGISTRY_HOST" -u "$REGISTRY_USERNAME" -p "$REGISTRY_PASSWORD"
docker tag some-gameserver-image "$REGISTRY_HOST/lanomat/gameservers/<name>:<tag>"
docker push "$REGISTRY_HOST/lanomat/gameservers/<name>:<tag>"
```

Point a Pelican egg's "Docker Images" field, or a `CustomServer`'s image
reference (`app/Modules/CustomServers/Actions/StartCustomServer.php` runs
`docker run <image> ...` on the target `RemoteHost` via
`RemoteExecutor` — see that action for the exact command shape), at
`$REGISTRY_HOST/lanomat/gameservers/<name>:<tag>` instead of a public
registry reference, so a LAN day's server (re)starts pull from the local/
low-latency registry instead of the public internet.

**Operational note:** before running `StartCustomServer` (or any command)
against a newly registered `RemoteHost`, probe it once (`ProbeHost`) — this
pins its SSH host-key fingerprint. `SshRemoteExecutor` trusts whatever key
is presented on a never-probed host's first connection (trust-on-first-use),
so probing first, on a trusted network path, is what closes that window.

## How a prod deploy pulls from the registry

On the deploy host:

```bash
docker login "$REGISTRY_HOST" -u "$REGISTRY_USERNAME" -p "$REGISTRY_PASSWORD"
```

Then either:

- point `compose.yml`'s `app`/`queue`/`scheduler`/`reverb-prod` services at
  the published image instead of a local `build:` context — replace each
  service's `build: {context: ., dockerfile: docker/Dockerfile}` with
  `image: ${REGISTRY_HOST}/lanomat/app:<tag>` and skip the local build
  entirely (`docker compose --profile prod pull && docker compose
  --profile prod up -d`); or
- keep the local `build:` for iterative dev/staging (as `compose.yml`
  ships today) and only use the registry image for the actual LAN-day prod
  host, so the host doesn't need the full build toolchain (Node, the
  Composer dev dependencies) — just `docker pull` + `docker compose up`.

`docs/prod-test.md`'s "Registry" section below documents this pull step
concretely as part of the one-time prod-deployment walkthrough.
