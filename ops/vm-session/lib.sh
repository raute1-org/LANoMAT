#!/usr/bin/env bash
# Shared helpers for the VM session scripts (sourced, not executed).

# load_secrets FILE — read KEY=VALUE lines as DATA (no shell evaluation), so
# passwords containing $ & ; # ` or spaces survive unchanged. Rules:
#   - blank lines and lines starting with # are ignored
#   - one pair of surrounding single or double quotes is stripped
#   - no inline comments, no variable expansion
#   - empty values are NOT exported ("not provided")
load_secrets() {
    local file="$1" line key val
    [ -f "$file" ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in '' | '#'*) continue ;; esac
        key="${line%%=*}"
        val="${line#*=}"
        [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue
        if [[ "$val" =~ ^\"(.*)\"$ ]] || [[ "$val" =~ ^\'(.*)\'$ ]]; then
            val="${BASH_REMATCH[1]}"
        fi
        # Expand a leading ~/ only (for SSH_KEY_FILE), nothing else.
        case "$val" in '~/'*) val="$HOME/${val#\~/}" ;; esac
        if [ -n "$val" ]; then
            export "$key=$val"
        fi
    done <"$file"
}

# secret_value FILE KEY — print one value using the same rules (no export).
secret_value() {
    ( # subshell: keep the environment clean
        unset "$2" 2>/dev/null || true
        load_secrets "$1"
        printf '%s' "${!2:-}"
    )
}
