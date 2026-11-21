#!/usr/bin/env bash
# Post one Discord announcement from a Markdown file through a channel webhook.
#
# Usage:
#   DISCORD_WEBHOOK_URL='https://discord.com/api/webhooks/...' \
#     scripts/ops/discord-announce.sh --file docs/ops/announcements/2026-11-first-lineup.md
#
#   scripts/ops/discord-announce.sh --file <md> --dry-run          # print the payload, send nothing
#   scripts/ops/discord-announce.sh --file <md> --webhook <url>    # URL from an argument instead of the env
#   scripts/ops/discord-announce.sh --file <md> --webhook-file <f> # URL from a 0600 file
#   scripts/ops/discord-announce.sh --file <md> --username LANoMAT --avatar https://.../logo.png
#   printf 'Text' | scripts/ops/discord-announce.sh --file -       # read the Markdown from stdin
#
# Precedence for the webhook URL: --webhook, --webhook-file, DISCORD_WEBHOOK_URL,
# DISCORD_WEBHOOK_FILE. The URL is never echoed; --webhook is visible in shell
# history and `ps`, so prefer the environment or the file.
#
# Content: Discord allows at most 2000 characters per message. Longer content is
# split at blank lines (then at word boundaries) into numbered parts like
# "[1/2] ...". --no-chunk makes oversized content an error instead.
# allowed_mentions is always {"parse":[]}, so the post never pings @everyone,
# roles or users.
#
# Exit codes: 0 sent (or dry-run printed), 1 request failed, 2 usage error,
#             3 content problem (empty, too long with --no-chunk, unreadable),
#             4 webhook missing.
#
# See docs/ops/discord-announcement.md. Requires bash, curl, jq and python3.
set -euo pipefail

WEBHOOK=""
WEBHOOK_FILE=""
FILE=""
DRY=0
NO_CHUNK=0
USERNAME=""
AVATAR=""
MAX_CHARS=2000

die() { # $1 exit code, $2... message
    local code="$1"
    shift
    printf 'discord-announce: %s\n' "$*" >&2
    exit "$code"
}

need_value() { # $1 option, $2 remaining arg count
    [ "$2" -ge 2 ] || die 2 "$1 needs a value"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --webhook) need_value "$1" $#; WEBHOOK="$2"; shift 2 ;;
        --webhook-file) need_value "$1" $#; WEBHOOK_FILE="$2"; shift 2 ;;
        --file) need_value "$1" $#; FILE="$2"; shift 2 ;;
        --username) need_value "$1" $#; USERNAME="$2"; shift 2 ;;
        --avatar) need_value "$1" $#; AVATAR="$2"; shift 2 ;;
        --max-chars) need_value "$1" $#; MAX_CHARS="$2"; shift 2 ;;
        --dry-run) DRY=1; shift ;;
        --no-chunk) NO_CHUNK=1; shift ;;
        -h|--help) sed -n '2,28p' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) die 2 "unknown argument: $1 (try --help)" ;;
    esac
done

[ -n "$FILE" ] || die 2 "--file is required (use --file - for stdin)"
if [ -z "$WEBHOOK" ] && [ -z "$WEBHOOK_FILE" ]; then
    if [ -n "${DISCORD_WEBHOOK_URL:-}" ]; then
        WEBHOOK="$DISCORD_WEBHOOK_URL"
    elif [ -n "${DISCORD_WEBHOOK_FILE:-}" ]; then
        WEBHOOK_FILE="$DISCORD_WEBHOOK_FILE"
    fi
fi

if [ -n "$WEBHOOK_FILE" ] && [ -z "$WEBHOOK" ]; then
    [ -r "$WEBHOOK_FILE" ] || die 2 "webhook file is not readable: $WEBHOOK_FILE"
    WEBHOOK="$(tr -d '[:space:]' < "$WEBHOOK_FILE")"
fi

case "$MAX_CHARS" in
    ''|*[!0-9]*) die 2 "--max-chars needs a number between 200 and 2000" ;;
esac
[ "$MAX_CHARS" -ge 200 ] && [ "$MAX_CHARS" -le 2000 ] \
    || die 2 "--max-chars must be between 200 and 2000 (Discord's hard limit is 2000)"

if [ -n "$USERNAME" ] && [ "${#USERNAME}" -gt 80 ]; then
    die 2 "--username may be at most 80 characters"
fi
if [ -n "$AVATAR" ]; then
    case "$AVATAR" in
        http://*|https://*) ;;
        *) die 2 "--avatar must be an http(s) URL" ;;
    esac
fi

if [ -z "$WEBHOOK" ] && [ "$DRY" -eq 0 ]; then
    die 4 "no webhook: set DISCORD_WEBHOOK_URL, use --webhook or --webhook-file (or pass --dry-run)"
fi

if [ -n "$WEBHOOK" ]; then
    case "$WEBHOOK" in
        https://discord.com/api/webhooks/*|https://discordapp.com/api/webhooks/*|\
        https://canary.discord.com/api/webhooks/*|https://ptb.discord.com/api/webhooks/*) ;;
        *) die 2 "the webhook URL does not look like a Discord webhook (https://discord.com/api/webhooks/...)" ;;
    esac
fi

command -v python3 >/dev/null 2>&1 || die 2 "python3 is not installed"
command -v jq >/dev/null 2>&1 || die 2 "jq is not installed"
if [ "$DRY" -eq 0 ]; then
    command -v curl >/dev/null 2>&1 || die 2 "curl is not installed"
fi

TMP="$(mktemp -d "${TMPDIR:-/tmp}/discord-announce.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

SRC="$FILE"
if [ "$FILE" = "-" ]; then
    SRC="$TMP/stdin.md"
    cat > "$SRC"
else
    [ -f "$SRC" ] || die 3 "file not found: $SRC"
    [ -r "$SRC" ] || die 3 "file is not readable: $SRC"
fi

# Split into message-sized parts. Python keeps blank-line paragraphs together
# and only breaks inside a paragraph when it is longer than one message.
if ! COUNT="$(python3 - "$SRC" "$MAX_CHARS" "$NO_CHUNK" "$TMP" <<'PY'
import os
import sys

src, max_chars, no_chunk, outdir = sys.argv[1], int(sys.argv[2]), sys.argv[3] == "1", sys.argv[4]

with open(src, encoding="utf-8") as fh:
    text = fh.read().replace("\r\n", "\n").replace("\r", "\n").strip()

if not text:
    print("content is empty", file=sys.stderr)
    sys.exit(3)

if len(text) > max_chars and no_chunk:
    print(
        f"content is {len(text)} characters, Discord allows {max_chars}; "
        "drop --no-chunk to split it automatically",
        file=sys.stderr,
    )
    sys.exit(3)

def split_words(block, limit):
    out, cur = [], ""
    for word in block.split(" "):
        candidate = word if not cur else cur + " " + word
        if len(candidate) <= limit:
            cur = candidate
            continue
        if cur:
            out.append(cur)
        while len(word) > limit:
            out.append(word[:limit])
            word = word[limit:]
        cur = word
    if cur:
        out.append(cur)
    return out

chunks = []
if len(text) <= max_chars:
    chunks = [text]
else:
    # Reserve room for the "[1/2] " prefix on every part.
    limit = max_chars - 12
    paragraphs = [p.strip() for p in text.split("\n\n") if p.strip()]
    pieces = []
    for paragraph in paragraphs:
        if len(paragraph) <= limit:
            pieces.append(paragraph)
        else:
            pieces.extend(split_words(paragraph, limit))
    cur = ""
    for piece in pieces:
        candidate = piece if not cur else cur + "\n\n" + piece
        if len(candidate) <= limit:
            cur = candidate
        else:
            if cur:
                chunks.append(cur)
            cur = piece
    if cur:
        chunks.append(cur)

for index, chunk in enumerate(chunks, start=1):
    path = os.path.join(outdir, f"part-{index:03d}.txt")
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(chunk)

print(len(chunks))
PY
)"; then
    die 3 "could not prepare the content (see above)"
fi

case "$COUNT" in
    ''|*[!0-9]*) die 3 "internal error: unexpected part count '$COUNT'" ;;
esac
[ "$COUNT" -ge 1 ] || die 3 "internal error: no message parts"

if [ "$COUNT" -gt 1 ]; then
    printf 'discord-announce: content split into %s messages (limit %s characters)\n' "$COUNT" "$MAX_CHARS" >&2
fi

redact() { # keep the webhook URL out of every message, including curl errors
    local s="$1"
    if [ -n "$WEBHOOK" ]; then
        s="${s//$WEBHOOK/<webhook>}"
    fi
    printf '%s' "$s"
}

build_payload() { # $1 content, $2 output file
    jq -n \
        --arg content "$1" \
        --arg username "$USERNAME" \
        --arg avatar "$AVATAR" \
        '{
            content: $content,
            allowed_mentions: {parse: []}
        }
        + (if $username != "" then {username: $username} else {} end)
        + (if $avatar != "" then {avatar_url: $avatar} else {} end)' > "$2"
}

post_once() { # $1 payload file, $2 response file; echoes the HTTP status
    local sep='?'
    case "$WEBHOOK" in
        *\?*) sep='&' ;;
    esac
    curl --silent --show-error --location --max-time 30 \
        --output "$2" --write-out '%{http_code}' \
        --header 'Content-Type: application/json' \
        --data-binary @"$1" \
        "${WEBHOOK}${sep}wait=true"
}

index=0
while [ "$index" -lt "$COUNT" ]; do
    index=$((index + 1))
    part="$TMP/part-$(printf '%03d' "$index").txt"
    content="$(cat "$part")"
    if [ "$COUNT" -gt 1 ]; then
        content="[$index/$COUNT] $content"
    fi
    if [ "${#content}" -gt "$MAX_CHARS" ]; then
        die 3 "internal error: part $index is longer than $MAX_CHARS characters"
    fi

    payload="$TMP/payload-$index.json"
    build_payload "$content" "$payload"

    if [ "$DRY" -eq 1 ]; then
        jq . "$payload"
        continue
    fi

    attempt=1
    while :; do
        response="$TMP/response-$index.json"
        if ! code="$(post_once "$payload" "$response" 2>"$TMP/curl.err")"; then
            err="$(cat "$TMP/curl.err" 2>/dev/null || true)"
            die 1 "request failed: $(redact "${err:-curl reported no detail}")"
        fi
        case "$code" in
            200|204) break ;;
            429)
                retry="$(jq -r '.retry_after // 1' "$response" 2>/dev/null || echo 1)"
                if [ "$attempt" -ge 2 ]; then
                    die 1 "rate limited twice (HTTP 429, retry_after=${retry}s)"
                fi
                printf 'discord-announce: rate limited, retrying in %ss\n' "$retry" >&2
                sleep "$retry"
                attempt=$((attempt + 1))
                ;;
            *)
                body="$(head -c 400 "$response" 2>/dev/null || true)"
                die 1 "Discord answered HTTP $code$( [ -n "$body" ] && printf ': %s' "$(redact "$body")")"
                ;;
        esac
    done

    id="$(jq -r '.id // empty' "$response" 2>/dev/null || true)"
    if [ -n "$id" ]; then
        printf 'posted %s/%s (message id %s)\n' "$index" "$COUNT" "$id"
    else
        printf 'posted %s/%s\n' "$index" "$COUNT"
    fi
done

if [ "$DRY" -eq 1 ]; then
    printf 'dry run: %s message(s) prepared, nothing sent\n' "$COUNT" >&2
fi
