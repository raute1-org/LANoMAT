#!/usr/bin/env bash
# Deploy the current origin/main on the production host (core profile).
#
# Run it ON the server inside the checkout (e.g. /opt/lanomat), or remotely:
#   ssh <ssh-user>@<APP_SERVER> /opt/lanomat/scripts/deploy/deploy.sh
#   (the real host and SSH user are kept out of this public repo)
#
# Pulls fast-forward only, rebuilds the image (pulling fresh base images), recreates the core services and
# runs migrations. It never touches volumes (no `down -v`) or `.env`.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

SERVICES=(traefik app queue scheduler reverb-prod postgres redis)

# The commit the running stack was last deployed from. Comparing against it (not against the
# HEAD before our own `git pull`) keeps the Traefik check right even when someone pulled by hand
# before running this script. Kept inside .git so it is never tracked.
DEPLOYED_MARKER="$(git rev-parse --git-dir)/lanomat-deployed-head"

echo "==> Pulling origin/main (fast-forward only)"
OLD_HEAD="$(git rev-parse HEAD)"
git pull --ff-only

BASE_HEAD="$OLD_HEAD"
if [ -f "$DEPLOYED_MARKER" ] && git cat-file -e "$(cat "$DEPLOYED_MARKER")^{commit}" 2>/dev/null; then
    BASE_HEAD="$(cat "$DEPLOYED_MARKER")"
fi

# The images carry no repository history: hand the version in. Same helper as the client build
# (scripts/ops/build-client.sh), so a client built from this checkout matches this server.
# shellcheck source=../ops/lib/version.sh
source scripts/ops/lib/version.sh
APP_VERSION="$(lanomat_version .)"
APP_COMMIT="$(lanomat_commit .)"
export APP_VERSION APP_COMMIT
echo "==> Version $APP_VERSION ($APP_COMMIT)"
if [ -f clients-dist/manifest.json ] && ! grep -q "\"app_version\": \"$APP_VERSION\"" clients-dist/manifest.json; then
    echo "NOTE: clients-dist holds a client build for another version." >&2
    echo "      Run scripts/ops/build-client.sh so /talk/client matches this server." >&2
fi

# --pull refreshes the moving base tags (FrankenPHP 1.13-php8.4, node:22-alpine)
# so every deploy ships the newest PHP/Node patch releases (security fixes).
echo "==> Building (pulling fresh base images) and starting: ${SERVICES[*]}"
docker compose --profile prod build --pull "${SERVICES[@]}"
docker compose --profile prod up -d "${SERVICES[@]}"

# Traefik bind-mounts single config FILES. `git pull` replaces a changed file with a new inode,
# but the running container keeps the old one, so `up -d` alone would silently keep serving the
# OLD routing/headers config. Recreate Traefik when anything under docker/traefik changed since the
# last deploy (a missing middleware makes Traefik drop the routers that use it: the site answers 404).
if ! git diff --quiet "$BASE_HEAD" HEAD -- docker/traefik compose.yml; then
    echo "==> Traefik config or compose.yml changed: recreating traefik"
    docker compose --profile prod up -d --force-recreate --no-deps traefik
fi

echo "==> Waiting for app to become healthy"
for _ in $(seq 1 30); do
    status=$(docker compose --profile prod ps --format '{{.Health}}' app)
    [ "$status" = "healthy" ] && break
    sleep 2
done
[ "${status:-}" = "healthy" ] || { echo "app is not healthy (status: ${status:-unknown})" >&2; exit 1; }

echo "==> Running migrations"
docker compose --profile prod exec -T app php artisan migrate --force

git rev-parse HEAD > "$DEPLOYED_MARKER"

echo "==> Done: $(git log --oneline -1)"
docker compose --profile prod ps --format 'table {{.Service}}\t{{.Status}}'
