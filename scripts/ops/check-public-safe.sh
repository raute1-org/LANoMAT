#!/usr/bin/env bash
# Public-safety check: fails when tracked text files in the publishable areas
# contain data that must never land in a PUBLIC repository (internal/public
# server IPs, personal e-mail addresses, SSH key material and fingerprints,
# Discord IDs). No network access. See CONTRIBUTING.md "Public repository rules".
#
# Usage:
#   scripts/ops/check-public-safe.sh [--root DIR] [--allowlist FILE]
#   scripts/ops/check-public-safe.sh --self-test
#
# Exit codes: 0 clean, 1 findings, 2 usage/self-test error.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ALLOWLIST="$SCRIPT_DIR/public-safe-allowlist.txt"
SELF_TEST=0

while [ $# -gt 0 ]; do
  case "$1" in
    --root) ROOT="$(cd "$2" && pwd)"; shift 2 ;;
    --allowlist) ALLOWLIST="$2"; shift 2 ;;
    --self-test) SELF_TEST=1; shift ;;
    -h|--help) sed -n '2,12p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

# Areas that are scanned (everything else is application code covered by gitleaks).
SCOPE_RE='^(docs/|scripts/|ops/|README\.md$|compose[^/]*\.yml$|docker/|\.github/)'
SKIP_RE='(\.lock$|-lock\.json$|lock\.yaml$|\.(png|jpe?g|gif|webp|ico|svg|woff2?|ttf|zip|gz|pdf|mp3|mp4)$)'

# --- patterns (PCRE) --------------------------------------------------------
OCTET='(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])'
QUAD="(?<![\\w.])${OCTET}(?:\\.${OCTET}){3}(?![\\w]|\\.[0-9])"
EMAIL='[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}(?![A-Za-z0-9-])'
SSH_PUB='(?:ssh-(?:rsa|ed25519|dss)|ecdsa-sha2-nistp[0-9]+) AAAA[0-9A-Za-z+/]{20,}'
PRIV_KEY='-----BEGIN [A-Z ]*PRIVATE KEY-----'
SSH_FP='SHA256:[A-Za-z0-9+/]{43}'
SNOWFLAKE='(?i)(?:guild(?:[ _-]?id)?|discord[ _-]?id|user id)\W{0,20}[0-9]{17,20}'

findings=0

redact() { # keep two leading chars only; never echo the matched value
  local s="$1"
  printf '%s[REDACTED len=%d]' "${s:0:2}" "${#s}"
}

allowlisted() { # $1 path, $2 token
  [ -f "$ALLOWLIST" ] || return 1
  local line p r
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in ''|'#'*) continue ;; esac
    p="${line%%:*}"; r="${line#*:}"
    if [ "$p" = "$1" ] && [[ "$2" =~ $r ]]; then return 0; fi
  done < "$ALLOWLIST"
  return 1
}

report() { # $1 path, $2 line, $3 rule, $4 token
  if allowlisted "$1" "$4"; then return 0; fi
  printf '%s:%s: [%s] %s\n' "$1" "$2" "$3" "$(redact "$4")"
  findings=$((findings + 1))
}

ip_class() { # echoes: private | ok | public
  local ip="$1" a b c d
  IFS=. read -r a b c d <<<"$ip"
  if [ "$a" -eq 10 ] \
    || { [ "$a" -eq 172 ] && [ "$b" -ge 16 ] && [ "$b" -le 31 ]; } \
    || { [ "$a" -eq 192 ] && [ "$b" -eq 168 ]; } \
    || { [ "$a" -eq 100 ] && [ "$b" -ge 64 ] && [ "$b" -le 127 ]; }; then
    echo private; return
  fi
  case "$ip" in
    1.1.1.1|8.8.8.8|9.9.9.9|0.0.0.0|255.255.255.255) echo ok; return ;;
  esac
  if [ "$a" -eq 127 ] || [ "$a" -eq 0 ] || [ "$a" -eq 255 ] \
    || { [ "$a" -eq 169 ] && [ "$b" -eq 254 ]; } \
    || { [ "$a" -eq 203 ] && [ "$b" -eq 0 ] && [ "$c" -eq 113 ]; } \
    || { [ "$a" -eq 198 ] && [ "$b" -eq 51 ] && [ "$c" -eq 100 ]; } \
    || { [ "$a" -eq 192 ] && [ "$b" -eq 0 ] && [ "$c" -eq 2 ]; }; then
    echo ok; return
  fi
  echo public
}

email_ok() { # $1 address
  local dom="${1#*@}"
  dom="${dom,,}"
  case "$1" in noreply@anthropic.com) return 0 ;; esac
  case "$dom" in
    lanomat.local|users.noreply.github.com) return 0 ;;
  esac
  [[ "$dom" =~ (^|\.)example\.[a-z]+$ ]]
}

list_files() {
  if [ "$(git -C "$ROOT" rev-parse --show-toplevel 2>/dev/null || true)" = "$ROOT" ]; then
    git -C "$ROOT" ls-files -z
  else
    (cd "$ROOT" && find . -type f -print0 | sed -z 's|^\./||')
  fi
}

scan() {
  local -a files=()
  local f
  while IFS= read -r -d '' f; do
    [[ "$f" =~ $SCOPE_RE ]] || continue
    [[ "$f" =~ $SKIP_RE ]] && continue
    [ -f "$ROOT/$f" ] || continue
    files+=("$f")
  done < <(list_files)
  [ "${#files[@]}" -gt 0 ] || return 0

  local path ln tok
  # grep -I skips binary files; -o prints only the matched token.
  while IFS=: read -r path ln tok; do
    case "$(ip_class "$tok")" in
      private) report "$path" "$ln" private-ip "$tok" ;;
      public) report "$path" "$ln" public-ip "$tok" ;;
    esac
  done < <(cd "$ROOT" && grep -nIoHP -- "$QUAD" "${files[@]}" || true)

  while IFS=: read -r path ln tok; do
    email_ok "$tok" || report "$path" "$ln" email "$tok"
  done < <(cd "$ROOT" && grep -nIoHP -- "$EMAIL" "${files[@]}" || true)

  local pair rule re
  for pair in "ssh-public-key|$SSH_PUB" "private-key|$PRIV_KEY" "ssh-fingerprint|$SSH_FP"; do
    rule="${pair%%|*}"; re="${pair#*|}"
    while IFS=: read -r path ln tok; do
      report "$path" "$ln" "$rule" "$tok"
    done < <(cd "$ROOT" && grep -nIoHP -- "$re" "${files[@]}" || true)
  done

  local digits
  while IFS=: read -r path ln tok; do
    digits="$(grep -oE '[0-9]{17,20}$' <<<"$tok" || true)"
    # Seeded demo ids (1 followed by zeros and a counter) are placeholders.
    [[ "$digits" =~ ^10{12,}[0-9]{0,2}$ ]] && continue
    report "$path" "$ln" discord-id "$tok"
  done < <(cd "$ROOT" && grep -nIoHP -- "$SNOWFLAKE" "${files[@]}" || true)
}

self_test() {
  local out rc fail=0
  tmp="$(mktemp -d)"
  trap 'rm -rf "${tmp:-}"' EXIT
  mkdir -p "$tmp/docs" "$tmp/src"
  : > "$tmp/allow.txt"

  expect() { # name expected-rc file-content [extra grep -c pattern that must be absent]
    local name="$1" want="$2" content="$3" leak="${4:-}"
    printf '%s\n' "$content" > "$tmp/docs/sample.md"
    rc=0; out="$(bash "${BASH_SOURCE[0]}" --root "$tmp" --allowlist "$tmp/allow.txt" 2>&1)" || rc=$?
    if [ "$rc" -ne "$want" ]; then echo "SELF-TEST FAIL: $name (rc=$rc want=$want)"; fail=1; return; fi
    if [ "$want" -eq 1 ]; then
      grep -q '^docs/sample\.md:1: \[' <<<"$out" || { echo "SELF-TEST FAIL: $name (no file:line)"; fail=1; }
      if [ -n "$leak" ] && grep -qF -- "$leak" <<<"$out"; then echo "SELF-TEST FAIL: $name (value not redacted)"; fail=1; fi
    fi
  }

  local dot=. ip1 ip2 ip3 pub
  ip1="10${dot}20${dot}30${dot}40"; ip2="192${dot}168${dot}1${dot}20"; ip3="100${dot}64${dot}0${dot}9"
  pub="93${dot}184${dot}216${dot}34"
  expect private-10 1 "host $ip1 here" "$ip1"
  expect private-192 1 "host $ip2" "$ip2"
  expect cgnat 1 "tailscale $ip3" "$ip3"
  expect public-ip 1 "server $pub" "$pub"
  expect email 1 "mail alice@gmail${dot}com" "alice@gmail"
  expect ssh-pub 1 "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"
  expect priv-key 1 "-----BEGIN OPENSSH PRIVATE KEY-----"
  expect fingerprint 1 "SHA256:abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ" "abcdefghij"
  expect snowflake 1 "guild id 123456789012345678" "123456789012345678"
  expect clean 0 "localhost 127${dot}0${dot}0${dot}1, dns 1${dot}1${dot}1${dot}1, doc 203${dot}0${dot}113${dot}7, bob@example.org, <APP_SERVER_IP>, discord_id 100000000000000001, noreply@anthropic.com"
  # Files outside the scanned areas are not this script's business.
  printf '%s\n' "host $ip1" > "$tmp/src/x.txt"
  expect out-of-scope 0 "clean"
  # Allowlist entries are honoured per file and token.
  printf '%s\n' "docs/sample.md:^${ip1//./\\.}\$" > "$tmp/allow.txt"
  expect allowlisted 0 "host $ip1"
  expect allowlist-is-narrow 1 "host $ip2"

  if [ "$fail" -eq 0 ]; then echo "self-test passed"; else exit 2; fi
}

if [ "$SELF_TEST" -eq 1 ]; then self_test; exit 0; fi

scan_out="$(scan)"
if [ -n "$scan_out" ]; then
  printf '%s\n' "$scan_out"
  echo "public-safe check FAILED: remove the data or (if a justified false positive) add it to scripts/ops/public-safe-allowlist.txt" >&2
  exit 1
fi
echo "public-safe check passed"
