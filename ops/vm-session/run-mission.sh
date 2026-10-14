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
# shellcheck disable=SC1091
. "${KIT}/lib.sh"
# Data-only parse (no shell evaluation); empty template values are not exported.
load_secrets "${SECRETS_FILE}"

if [ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ] && [ -z "${ANTHROPIC_API_KEY:-}" ] && [ ! -f "$HOME/.claude/.credentials.json" ]; then
    echo "No Claude credentials: set CLAUDE_CODE_OAUTH_TOKEN in ${SECRETS_FILE} (claude setup-token) or run 'claude' once and log in." >&2
    exit 1
fi

if [ -n "${GH_TOKEN:-}" ]; then
    if [ -z "${GIT_EMAIL:-}" ]; then
        echo "GH_TOKEN is set but GIT_EMAIL is empty in ${SECRETS_FILE}." >&2
        exit 1
    fi
    git config --global user.name "${GIT_NAME:-LANoMAT VM session}"
    git config --global user.email "${GIT_EMAIL}"
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

# Binding working method (user decision): default model sonnet, advisor opus,
# superpowers + frontend-design plugins always loaded, implementer subagents on haiku (see MISSION.md).
MISSION_MODEL="${MISSION_MODEL:-sonnet}"
MISSION_ADVISOR="${MISSION_ADVISOR:-opus}"
for plugin in superpowers frontend-design; do
    if ! claude plugin list 2>/dev/null | grep -q "${plugin}"; then
        claude plugin install "${plugin}@claude-plugins-official" >>"${STATE_DIR}/log/plugins.log" 2>&1 \
            || "${KIT}/notify.sh" "Plugin ${plugin} konnte nicht installiert werden (siehe log/plugins.log)."
    fi
done
for plugin in superpowers frontend-design; do
    claude plugin list 2>/dev/null | grep -q "${plugin}" || {
        echo "Required plugin '${plugin}' is not installed; see ${STATE_DIR}/log/plugins.log" >&2
        exit 1
    }
done

SESSION_FILE="${STATE_DIR}/session_id"
MISSION="$(cat "${KIT}/MISSION.md")"
iteration=0

while [ "${iteration}" -lt "${MAX_ITERATIONS}" ]; do
    if [ -f "${STATE_DIR}/DONE" ]; then
        "${KIT}/notify.sh" "Mission abgeschlossen. Details: ${STATE_DIR}/STATE.md"
        exit 0
    fi

    # The session asked the operator for input (WAITING_HUMAN holds the question):
    # resume once ANSWERS.md has content that was not consumed yet (tracked by hash, not mtime).
    if [ -f "${STATE_DIR}/WAITING_HUMAN" ]; then
        current="$(sha256sum "${STATE_DIR}/ANSWERS.md" 2>/dev/null | cut -d' ' -f1)"
        consumed="$(cat "${STATE_DIR}/.answers-consumed" 2>/dev/null || true)"
        if [ -s "${STATE_DIR}/ANSWERS.md" ] && [ "${current}" != "${consumed}" ]; then
            printf '%s' "${current}" >"${STATE_DIR}/.answers-consumed"
            rm -f "${STATE_DIR}/WAITING_HUMAN"
        else
            sleep "${HUMAN_POLL}"
            continue
        fi
    fi

    iteration=$((iteration + 1))
    out="${STATE_DIR}/log/iter-$(printf '%03d' "${iteration}").json"
    echo "[$(date -Is)] iteration ${iteration}"

    common=(--dangerously-skip-permissions --output-format json --model "${MISSION_MODEL}" --advisor "${MISSION_ADVISOR}")
    started=$(date +%s)
    if [ -s "${SESSION_FILE}" ]; then
        prompt="Weiter. Lies zuerst ${STATE_DIR}/STATE.md und ${STATE_DIR}/ANSWERS.md (falls vorhanden; Einträge, die du noch nicht in STATE.md als erledigt vermerkt hast, haben Vorrang), dann arbeite an der Mission weiter, bis alles läuft oder du wirklich auf den Operator warten musst."
        timeout "${ITERATION_TIMEOUT}" claude -p "${prompt}" --resume "$(cat "${SESSION_FILE}")" "${common[@]}" >"${out}" 2>"${out}.err"
    else
        # Fixed session id: a timeout or crash on the very first run still leaves a resumable session.
        printf '%s' "$(cat /proc/sys/kernel/random/uuid)" >"${SESSION_FILE}"
        timeout "${ITERATION_TIMEOUT}" claude -p "${MISSION}" --session-id "$(cat "${SESSION_FILE}")" "${common[@]}" >"${out}" 2>"${out}.err"
    fi
    status=$?

    # exit 0 can still carry an error result (e.g. usage limit): inspect the JSON too.
    is_error="$(jq -r '.is_error // false' "${out}" 2>/dev/null || echo false)"

    if [ "${status}" -ne 0 ] || [ "${is_error}" = "true" ]; then
        if grep -qiE 'no conversation found|session.*not found' "${out}" "${out}.err" 2>/dev/null; then
            # The session is gone (VM rebuilt, pruned): start a fresh one right away, STATE.md carries the memory.
            rm -f "${SESSION_FILE}"
            continue
        fi
        if [ "${status}" -eq 124 ]; then
            "${KIT}/notify.sh" "Iteration ${iteration} lief ins Zeitlimit (${ITERATION_TIMEOUT}); mache weiter."
            sleep 30
            continue
        fi
        "${KIT}/notify.sh" "Iteration ${iteration} Fehler (exit ${status}, is_error=${is_error}); neuer Versuch in $((ERROR_SLEEP / 60)) Min."
        sleep "${ERROR_SLEEP}"
        continue
    fi

    if [ -f "${STATE_DIR}/WAITING_HUMAN" ]; then
        "${KIT}/notify.sh" "Brauche dich: $(head -c 400 "${STATE_DIR}/WAITING_HUMAN")"
    fi

    # Never spin: an iteration that returned almost immediately gets a pause.
    if [ $(($(date +%s) - started)) -lt 60 ]; then
        sleep "${MIN_ITERATION_GAP:-60}"
    fi
done

"${KIT}/notify.sh" "Mission nach ${MAX_ITERATIONS} Iterationen ohne DONE beendet – bitte prüfen."
exit 2
