#!/usr/bin/env bash
# MCP / agent / docs smoke: probes the four surfaces a deployed LANoMAT must
# answer on, with the status codes a correct install returns.
#
# Run it after a deploy, after a module-profile change, or from a monitoring
# cron. It needs `curl` only and never prints a token.
#
# Usage:
#   scripts/ops/mcp-smoke.sh [--base URL] [--docs URL] [--token TOKEN]
#
#   --base   app origin                      (default http://127.0.0.1)
#   --docs   docs origin                     (default <base>/docs/)
#   --token  a real MCP token: adds the authenticated checks (/api/v1/me and
#            an MCP initialize). Without it the script only proves the 401s.
#
# Exit codes: 0 all probes as expected | 1 at least one probe failed |
#             2 usage error / curl missing.
#
# See docs/infra/mcp-ops.md, section "Preflight and health probes".
set -euo pipefail

BASE="http://127.0.0.1"
DOCS=""
TOKEN=""

die2() { printf 'usage error: %s\n' "$*" >&2; exit 2; }

while [ $# -gt 0 ]; do
  case "$1" in
    --base) [ $# -ge 2 ] || die2 "--base needs a value"; BASE="${2%/}"; shift 2 ;;
    --docs) [ $# -ge 2 ] || die2 "--docs needs a value"; DOCS="${2%/}"; shift 2 ;;
    --token) [ $# -ge 2 ] || die2 "--token needs a value"; TOKEN="$2"; shift 2 ;;
    -h|--help) sed -n '2,18p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) die2 "unknown argument: $1" ;;
  esac
done

command -v curl >/dev/null 2>&1 || die2 "curl is not installed"
DOCS="${DOCS:-$BASE/docs/}"

FAILED=0
TOTAL=0

# probe <label> <expected-code> <method> <url> [curl args...]
probe() {
  local label=$1 expected=$2 method=$3 url=$4
  shift 4
  local code
  TOTAL=$((TOTAL + 1))
  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 -X "$method" "$@" "$url" 2>/dev/null) || code=000

  if [ "$code" = "$expected" ]; then
    printf 'ok    %-42s %s\n' "$label" "$code"
  else
    printf 'FAIL  %-42s %s (expected %s)\n' "$label" "$code" "$expected"
    FAILED=$((FAILED + 1))
  fi
}

# --- unauthenticated: every one of these must be a clean answer, not a 5xx,
#     not a login redirect and not a 404 from a switched-off module. ----------
probe "GET /up"                            200 GET  "$BASE/up"
probe "POST /mcp (no bearer)"              401 POST "$BASE/mcp" \
  -H 'Accept: application/json, text/event-stream' \
  -H 'Content-Type: application/json' \
  --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"mcp-smoke","version":"1"}}}'
probe "GET /api/v1/me (no bearer)"         401 GET  "$BASE/api/v1/me" -H 'Accept: application/json'
probe "GET /docs/ (docs site)"             200 GET  "$DOCS"

# --- authenticated: only with a token. ---------------------------------------
if [ -n "$TOKEN" ]; then
  probe "GET /api/v1/me (bearer)"          200 GET  "$BASE/api/v1/me" \
    -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
  probe "POST /mcp initialize (bearer)"    200 POST "$BASE/mcp" \
    -H 'Accept: application/json, text/event-stream' \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $TOKEN" \
    --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"mcp-smoke","version":"1"}}}'
fi

printf '\n%d/%d probes as expected.\n' "$((TOTAL - FAILED))" "$TOTAL"

if [ "$FAILED" -gt 0 ]; then
  printf '%d failed. See docs/infra/mcp-ops.md for what each probe means.\n' "$FAILED" >&2
  exit 1
fi
