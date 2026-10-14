#!/usr/bin/env bash
# Send a short status message to the operator's phone: `notify.sh "text"`.
# Uses ntfy.sh (NTFY_TOPIC) and/or a Discord webhook (DISCORD_WEBHOOK_URL) from
# the secrets file; with neither configured it only prints.
set -uo pipefail

MSG="${*:-}"
[ -n "${MSG}" ] || exit 0
echo "[notify] ${MSG}"

if [ -n "${NTFY_TOPIC:-}" ]; then
    curl -fsS -m 15 -H "Title: LANoMAT VM session" -d "${MSG}" "https://ntfy.sh/${NTFY_TOPIC}" >/dev/null 2>&1 || true
fi

if [ -n "${DISCORD_WEBHOOK_URL:-}" ]; then
    payload="$(jq -n --arg c "LANoMAT VM session: ${MSG}" '{content: $c}')"
    curl -fsS -m 15 -H 'Content-Type: application/json' -d "${payload}" "${DISCORD_WEBHOOK_URL}" >/dev/null 2>&1 || true
fi

exit 0
