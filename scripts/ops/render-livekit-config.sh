#!/usr/bin/env bash
# Renders docker/livekit/livekit.yaml from the template using the LIVEKIT_* values of the
# environment (or of a .env file given with --env-file). The output contains the API secret and is
# gitignored. Run it before `docker compose --profile web-voice up -d livekit`.
#
# Usage:
#   LIVEKIT_NODE_IP=<VENUE_SERVER_IP> LIVEKIT_API_KEY=... LIVEKIT_API_SECRET=... scripts/ops/render-livekit-config.sh
#   scripts/ops/render-livekit-config.sh --env-file /path/to/.env
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
TEMPLATE="$ROOT/docker/livekit/livekit.yaml.template"
OUTPUT="$ROOT/docker/livekit/livekit.yaml"

if [ "${1:-}" = "--env-file" ]; then
  [ -f "${2:-}" ] || { echo "env file not found: ${2:-}" >&2; exit 2; }
  set -a
  # shellcheck disable=SC1090
  . "$2"
  set +a
fi

for var in LIVEKIT_NODE_IP LIVEKIT_API_KEY LIVEKIT_API_SECRET; do
  if [ -z "${!var:-}" ]; then
    echo "$var is not set" >&2
    exit 2
  fi
done

# A short secret is a weak HMAC key; LiveKit's own tooling generates long ones.
if [ "${#LIVEKIT_API_SECRET}" -lt 32 ]; then
  echo "LIVEKIT_API_SECRET must be at least 32 characters" >&2
  exit 2
fi

umask 077
envsubst '${LIVEKIT_NODE_IP} ${LIVEKIT_API_KEY} ${LIVEKIT_API_SECRET}' < "$TEMPLATE" > "$OUTPUT"
echo "Wrote $OUTPUT (mode 600). Start with: docker compose --profile web-voice up -d livekit"
