#!/usr/bin/env bash
# Supervisor for the unattended LANoMAT build session. Run as the `claude`
# user inside tmux:   tmux new -s mission; bash ~/LANoMAT/ops/vm-session/run-mission.sh
#
# It runs `claude -p` in a loop. The durable memory is $STATE_DIR/STATE.md, so
# every iteration (resumed session or fresh one after a crash) can pick up
# where the last stopped. The loop ends when $STATE_DIR/DONE exists, sleeps
# while the session waits for the operator (WAITING_HUMAN), and backs off on
# errors such as usage limits.
set -uo pipefail

REPO="${REPO:-$HOME/LANoMAT}"
KIT="${REPO}/ops/vm-session"
STATE_DIR="${STATE_DIR:-$HOME/mission-state}"
SECRETS_FILE="${SECRETS_FILE:-$HOME/secrets/lanomat.env}"
MAX_ITERATIONS="${MAX_ITERATIONS:-300}"
ITERATION_TIMEOUT="${ITERATION_TIMEOUT:-5h}"
ERROR_SLEEP="${ERROR_SLEEP:-900}"
HUMAN_POLL="${HUMAN_POLL:-300}"

mkdir -p "${STATE_DIR}/log"
export PATH="$HOME/.local/bin:$PATH"

if [ ! -f "${SECRETS_FILE}" ]; then
    echo "Missing ${SECRETS_FILE}" >&2
    exit 1
fi
set -a
# shellcheck disable=SC1090
. "${SECRETS_FILE}"
set +a
# Empty values from the template must count as "not provided".
for v in CLAUDE_CODE_OAUTH_TOKEN ANTHROPIC_API_KEY GH_TOKEN NTFY_TOPIC DISCORD_WEBHOOK_URL; do
    [ -n "${!v:-}" ] || unset "$v"
done

if [ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ] && [ -z "${ANTHROPIC_API_KEY:-}" ] && [ ! -f "$HOME/.claude/.credentials.json" ]; then
    echo "No Claude credentials: set CLAUDE_CODE_OAUTH_TOKEN in ${SECRETS_FILE} (claude setup-token) or run 'claude' once and log in." >&2
    exit 1
fi

if [ -n "${GH_TOKEN:-}" ]; then
    git config --global user.name "${GIT_NAME:-LANoMAT VM session}"
    git config --global user.email "${GIT_EMAIL:?set GIT_EMAIL in the secrets file}"
    gh auth setup-git >/dev/null 2>&1 || true
fi

cd "${REPO}" || exit 1
git pull --ff-only >/dev/null 2>&1 || true

# Seed the state file once so the mission can rely on it.
[ -f "${STATE_DIR}/STATE.md" ] || cat >"${STATE_DIR}/STATE.md" <<'EOF'
# Mission state (written by the session; German, for the operator)

Phase: not started
Done: -
Next: Phase 1
Blocked on operator: -
Findings: -
EOF

SESSION_FILE="${STATE_DIR}/session_id"
MISSION="$(cat "${KIT}/MISSION.md")"
iteration=0

while [ "${iteration}" -lt "${MAX_ITERATIONS}" ]; do
    if [ -f "${STATE_DIR}/DONE" ]; then
        "${KIT}/notify.sh" "Mission abgeschlossen. Details: ${STATE_DIR}/STATE.md"
        exit 0
    fi

    # The session asked the operator for input (WAITING_HUMAN holds the question):
    # wait until a non-empty ANSWERS.md newer than the question appears.
    if [ -f "${STATE_DIR}/WAITING_HUMAN" ]; then
        if [ -s "${STATE_DIR}/ANSWERS.md" ] && [ "${STATE_DIR}/ANSWERS.md" -nt "${STATE_DIR}/WAITING_HUMAN" ]; then
            rm -f "${STATE_DIR}/WAITING_HUMAN"
        else
            sleep "${HUMAN_POLL}"
            continue
        fi
    fi

    iteration=$((iteration + 1))
    out="${STATE_DIR}/log/iter-$(printf '%03d' "${iteration}").json"
    echo "[$(date -Is)] iteration ${iteration}"

    common=(--dangerously-skip-permissions --output-format json)
    if [ -s "${SESSION_FILE}" ]; then
        prompt="Weiter. Lies zuerst ${STATE_DIR}/STATE.md und ${STATE_DIR}/ANSWERS.md (falls vorhanden; neue Antworten des Operators haben Vorrang), dann arbeite an der Mission weiter, bis alles läuft oder du wirklich auf den Operator warten musst."
        timeout "${ITERATION_TIMEOUT}" claude -p "${prompt}" --resume "$(cat "${SESSION_FILE}")" "${common[@]}" >"${out}" 2>"${out}.err"
    else
        timeout "${ITERATION_TIMEOUT}" claude -p "${MISSION}" "${common[@]}" >"${out}" 2>"${out}.err"
    fi
    status=$?

    sid="$(jq -r '.session_id // empty' "${out}" 2>/dev/null || true)"
    [ -n "${sid}" ] && printf '%s' "${sid}" >"${SESSION_FILE}"

    if [ "${status}" -ne 0 ]; then
        # Resume failed or usage limit reached: start a fresh session next time
        # only when the session itself is gone; otherwise just back off.
        if grep -qiE 'no conversation found|session.*not found' "${out}" "${out}.err" 2>/dev/null; then
            rm -f "${SESSION_FILE}"
        fi
        "${KIT}/notify.sh" "Iteration ${iteration} endete mit Fehler (exit ${status}); neuer Versuch in $((ERROR_SLEEP / 60)) Min."
        sleep "${ERROR_SLEEP}"
        continue
    fi

    if [ -f "${STATE_DIR}/WAITING_HUMAN" ]; then
        "${KIT}/notify.sh" "Brauche dich: $(head -c 400 "${STATE_DIR}/WAITING_HUMAN")"
    fi
done

"${KIT}/notify.sh" "Mission nach ${MAX_ITERATIONS} Iterationen ohne DONE beendet – bitte prüfen."
exit 2
