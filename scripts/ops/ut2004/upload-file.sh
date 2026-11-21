#!/usr/bin/env bash
# Resumable tus upload to the live LANoMAT file host (event raute1-lan-26).
#
#   ./upload_resumable.sh <file> <name-on-site> [chunk-mib]
#
# Requires the admin session cookie in /tmp/opencode/cookies.txt (Cookie header
# format). State (the tus upload URL) is kept under ~/.cache/opencode-upload-test/,
# so re-running the same command after a broken connection resumes at the offset
# tusd reports (HEAD), without asking for a new ticket.
set -euo pipefail

FILE=${1:?usage: upload_resumable.sh <file> <name> [chunk-mib]}
NAME=${2:?usage: upload_resumable.sh <file> <name> [chunk-mib]}
CHUNK_MIB=${3:-64}

BASE=https://lan.raute1.org
EVENT=raute1-lan-26
CACHE=$HOME/.cache/opencode-upload-test
JAR=$CACHE/jar.txt
STATE=$CACHE/state-$(printf %s "$NAME" | sha256sum | cut -c1-16).env
CHUNK=$CACHE/chunk.bin
mkdir -p "$CACHE"
SIZE=$(stat -c%s "$FILE")
COOKIE=$(cat /tmp/opencode/cookies.txt)

# 1) Session + CSRF (the meta token is plain; the XSRF cookie is encrypted).
curl -sS -b "$COOKIE" -c "$JAR" "$BASE/events/$EVENT/files" -o "$CACHE/page.html"
CSRF=$(grep -o 'name="csrf-token" content="[^"]*"' "$CACHE/page.html" | head -1 | sed 's/.*content="//;s/"//')

if [ -f "$STATE" ]; then
  # shellcheck disable=SC1090
  . "$STATE"
  echo "resuming $NAME at tusd offset (HEAD follows)"
else
  # 2) Ticket: reserves quota, binds name+size, single use (10 min TTL).
  curl -sS -b "$JAR" -c "$JAR" -H "X-CSRF-TOKEN: $CSRF" -H 'Accept: application/json' \
    -H 'Content-Type: application/json' -H 'X-Requested-With: XMLHttpRequest' \
    -d "{\"name\":\"$NAME\",\"size\":$SIZE,\"type\":\"application/octet-stream\"}" \
    "$BASE/events/$EVENT/files/uploads" -o "$CACHE/ticket.json" -w 'ticket_http=%{http_code}\n'

  python3 - "$CACHE/ticket.json" "$NAME" > "$CACHE/meta.txt" <<'PY'
import base64,json,sys
d=json.load(open(sys.argv[1])); b=lambda s: base64.b64encode(s.encode()).decode()
print(f"filename {b(sys.argv[2])},filetype {b('application/octet-stream')},ticket {b(d['ticket'])}")
PY
  EP=$(python3 -c "import json;print(json.load(open('$CACHE/ticket.json'))['endpoint'])")

  # 3) tus create: the app's pre-create hook consumes the ticket and chooses the
  #    upload id + final path on the file host.
  curl -sS -D "$CACHE/create.headers" -o /dev/null -X POST "$EP" \
    -H 'Tus-Resumable: 1.0.0' -H "Upload-Length: $SIZE" \
    -H "Upload-Metadata: $(cat "$CACHE/meta.txt")" \
    -H 'Content-Type: application/offset+octet-stream' --data-binary '' -w 'create_http=%{http_code}\n'
  LOC=$(awk 'tolower($1)=="location:"{print $2}' "$CACHE/create.headers" | tr -d '\r')
  printf 'LOC=%q\nSIZE=%q\n' "$LOC" "$SIZE" > "$STATE"
  echo "upload_url=$LOC"
fi

# 4) PATCH chunk by chunk; after every response (or failure) HEAD the real offset.
fails=0
while :; do
  OFF=$(curl -sS -I -X HEAD "$LOC" -H 'Tus-Resumable: 1.0.0' |
        awk 'tolower($1)=="upload-offset:"{gsub("\r","");print $2}')
  if [ "$OFF" = "$SIZE" ]; then echo "complete: $SIZE bytes"; break; fi
  N=$(( SIZE - OFF )); [ "$N" -gt $(( CHUNK_MIB * 1024 * 1024 )) ] && N=$(( CHUNK_MIB * 1024 * 1024 ))
  dd if="$FILE" of="$CHUNK" bs=1M iflag=skip_bytes,count_bytes skip="$OFF" count="$N" status=none
  CODE=$(curl -sS -o "$CACHE/patch.body" -w '%{http_code}' -X PATCH "$LOC" \
    -H 'Tus-Resumable: 1.0.0' -H "Upload-Offset: $OFF" \
    -H 'Content-Type: application/offset+octet-stream' --data-binary "@$CHUNK") || CODE=000
  echo "$(date +%T) offset=$OFF sent=$N http=$CODE"
  if [ "$CODE" = 204 ]; then fails=0; else
    fails=$((fails+1)); echo "retry $fails (body: $(head -c 200 "$CACHE/patch.body"))" >&2
    [ "$fails" -ge 10 ] && { echo "giving up; re-run to resume" >&2; exit 1; }
    sleep $(( fails * 5 ))
  fi
done
rm -f "$CHUNK"
