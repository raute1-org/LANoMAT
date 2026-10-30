#!/usr/bin/env bash
# Deploy the current origin/main on the production host (core profile).
#
# Run it ON the server inside the checkout (e.g. /opt/lanomat), or remotely:
#   ssh root@lan.raute1.org /opt/lanomat/scripts/deploy/deploy.sh
#
# Pulls fast-forward only, rebuilds the image, recreates the core services and
# runs migrations. It never touches volumes (no `down -v`) or `.env`.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

SERVICES=(traefik app queue scheduler reverb-prod postgres redis)

echo "==> Pulling origin/main (fast-forward only)"
git pull --ff-only

echo "==> Building and starting: ${SERVICES[*]}"
docker compose --profile prod up -d --build "${SERVICES[@]}"

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
