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

echo "==> Pulling origin/main (fast-forward only)"
git pull --ff-only

# --pull refreshes the moving base tags (FrankenPHP 1.13-php8.4, node:22-alpine)
# so every deploy ships the newest PHP/Node patch releases (security fixes).
echo "==> Building (pulling fresh base images) and starting: ${SERVICES[*]}"
docker compose --profile prod build --pull "${SERVICES[@]}"
docker compose --profile prod up -d "${SERVICES[@]}"

echo "==> Waiting for app to become healthy"
for _ in $(seq 1 30); do
    status=$(docker compose --profile prod ps --format '{{.Health}}' app)
    [ "$status" = "healthy" ] && break
    sleep 2
done
[ "${status:-}" = "healthy" ] || { echo "app is not healthy (status: ${status:-unknown})" >&2; exit 1; }

echo "==> Running migrations"
docker compose --profile prod exec -T app php artisan migrate --force

echo "==> Done: $(git log --oneline -1)"
docker compose --profile prod ps --format 'table {{.Service}}\t{{.Status}}'
