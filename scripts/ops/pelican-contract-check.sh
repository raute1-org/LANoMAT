#!/usr/bin/env bash
# Live contract smoke test against a Pelican panel. NOT run in CI.
#
# Reads everything from the environment (nothing is stored or printed):
#   PELICAN_PANEL_URL          panel base URL
#   PELICAN_APPLICATION_TOKEN  Application API key (papp_...)
#   PELICAN_CLIENT_TOKEN       Client API key (pacc_...) of the non-admin service user
#   PELICAN_DEFAULT_NODE_ID    optional, first node is used otherwise
#   PELICAN_OWNER_USER_ID      only for --lifecycle
#
# Usage:
#   scripts/ops/pelican-contract-check.sh                       # read-only
#   scripts/ops/pelican-contract-check.sh --lifecycle --egg=ID  # + create/start/stop/delete ONE throwaway server
#
# Prints PASS/FAIL per call; exits non-zero on any FAIL. See docs/gameservers-pelican.md.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

missing=()
for var in PELICAN_PANEL_URL PELICAN_APPLICATION_TOKEN PELICAN_CLIENT_TOKEN; do
  [ -n "${!var:-}" ] || missing+=("$var")
done
if [ ${#missing[@]} -gt 0 ]; then
  echo "missing environment variable(s): ${missing[*]}" >&2
  exit 2
fi

exec php artisan gameservers:pelican-check "$@"
