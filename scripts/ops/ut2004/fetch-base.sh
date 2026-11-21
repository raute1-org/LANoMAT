#!/usr/bin/env bash
# fetch-base.sh - download the big UT2004 base archive from archive.org, resumably and in parallel.
#
# PURPOSE
#   archive.org's edge nodes routinely stop a single long `curl -C -` transfer at 45-120 MB with an
#   SSL EOF or a silent stall. A single connection also cannot use the available bandwidth. This tool
#   asks archive.org's metadata API for the item's server and file size, then downloads the file as N
#   independent byte ranges with `curl -r`. Each range is its own append-only part file, so a broken
#   connection costs only the bytes that were in flight: the next attempt resumes exactly there.
#
# USAGE
#   scripts/ops/ut2004/fetch-base.sh [options]
#
#   --item ITEM             archive.org identifier (default: unreal-tournament-2004_202405)
#   --file NAME             file inside the item (default: Unreal Tournament 2004.zip)
#   --output PATH           final file (default: ~/Downloads/Unreal Tournament 2004.zip)
#   --parts-dir DIR         where part files live (default: <output>.parts)
#   --parallel N            number of ranges/connections, 1-16 (default: 4)
#   --expected-size BYTES   assert the total size; must agree with the metadata API
#   --sha256 HEX            verify the finished file (metadata sha256 is used when it has one)
#   --dry-run               show metadata, mirrors, reuse plan and ranges; write nothing
#   --adopt SPEC            adopt legacy parts, e.g. p1=577169443-1070000000,p2=...
#   --adopt-running         derive the layout from curl processes currently writing --parts-dir
#   --take-over             stop only the writers that hold our part files, then continue
#   --fresh-parts           move unrecognised files in --parts-dir aside, then plan from scratch
#   --keep-parts            keep part files and manifest after a successful assembly
#   --metadata-url URL      metadata endpoint (default: https://archive.org/metadata/<item>)
#   --mirror URL            use this mirror (repeatable; skips mirror discovery)
#   --max-time SECONDS      per-attempt curl limit (default: 1800)
#   --speed-limit BYTES/S   abort an attempt below this rate (default: 10240 = 10 KiB/s)
#   --speed-time SECONDS    ... for this long (default: 60)
#   --retries N             attempts per range before giving up (default: 12)
#   --no-probe              do not HEAD the mirrors before downloading
#   -h, --help              this text
#
# HOW IT WORKS
#   1. GET the metadata JSON, find the exact file entry, read `size`, optional `sha256`, and the
#      item's `server`/`d1`/`d2` hosts plus `dir`. Mirrors are tried best-first; each attempt may
#      rotate to the next mirror. `https://archive.org/download/<item>/<file>` is the last fallback
#      (it redirects to a healthy node). All mirrors are HEAD-probed once and must report the exact
#      expected Content-Length; broken mirrors are skipped.
#   2. An existing output file is treated as a verified prefix: bytes 0..N-1 are assumed valid, and
#      the plan covers exactly [N, size). Existing part files are resumed at their current size.
#      A manifest (parts-dir/manifest.json) pins item/file/size/prefix/range boundaries across runs.
#      Without a manifest, ranges are recovered from the `part-XX-<start>.bin` names; legacy names
#      such as p1..p4 need --adopt or --adopt-running (see below).
#   3. Every range is fetched with `curl -r start-end` into a temporary tail file. Response headers
#      (HTTP 206 + Content-Range) are validated before the bytes are appended to the part, so a
#      server that ignores Range and sends the whole file cannot corrupt the part. `--max-time`,
#      `--speed-limit`/`--speed-time` and an outer retry loop turn stalls/SSL EOFs into a resume
#      instead of a dead download. Full parts are never re-requested.
#   4. Progress (bytes, percent, speed, ETA, per-part state) is printed while the ranges run.
#   5. On success every part is checked for its exact range length, the output is assembled to
#      `<output>.tmp.<pid>` (prefix + parts in offset order), checked against the expected size,
#      verified against sha256 when available, atomically renamed into place, and the temporary
#      files are removed. On failure/interrupt the parts are kept for the next run.
#
# RESUME AND LEGACY PARTS
#   A run of this tool always resumes itself. For a download started by hand, e.g.
#     ~/Downloads/Unreal Tournament 2004.zip       (prefix, already complete bytes)
#     /tmp/opencode/utparts/p1..p4                 (ranges, possibly still growing)
#   use one of:
#     --parts-dir /tmp/opencode/utparts --adopt p1=577169443-1070000000,p2=...
#     --parts-dir /tmp/opencode/utparts --adopt-running    # reads curl -r/-o from /proc
#   Adoption is validated strictly: the first range must start at the prefix size, ranges must be
#   contiguous, and the last one must end at size-1. If the original curls are still running, the
#   tool refuses to write to the same files (exit 7) unless --take-over is given. --take-over sends
#   SIGTERM only to the PIDs that hold those exact files, waits for them, and then resumes.
#   p1..p4 without metadata/logs cannot be mapped safely by size alone, so the tool never guesses:
#   it stops with exit 2 and asks for --adopt, --adopt-running or --fresh-parts.
#
# LIMITS
#   - The prefix/parts layout is only as good as the assumption that a partial output is a valid
#     byte prefix. Do not keep another writer on the output file while running.
#   - Assembly needs roughly the full file size free on the output filesystem (parts are still
#     there until verification passes).
#   - archive.org may throttle a node or withdraw it mid-run; mirror rotation is per attempt, not
#     mid-stream. A single range can therefore still be slow.
#   - No metadata -> no start. The script deliberately does not guess a size when the API is down;
#     pass --expected-size plus --mirror only if you already know both.
#   - sha256 is only checked when supplied or published in metadata. This item publishes md5, not
#     sha256, so by default the size check is the final integrity check.
#   - Only Linux/POSIX: uses bash, curl, python3, sha256sum, stat, flock, df. No pkill, no secrets.
#
# CURRENT BLOCKER
#   archive.org stalls/SSL-EOFs long single-stream downloads at ~45-120 MB. The parallel range
#   approach is fast but was started by hand and is fragile: one range with no per-attempt timeout
#   can hang forever. This script adds the timeout/retry/resume/validation loop and can adopt the
#   hand-started parts without re-downloading them.
#
# EXIT CODES
#   0  complete (downloaded now, or already at the expected size)
#   2  usage/configuration/layout problem (nothing modified)
#   3  download failed after all retries (parts kept)
#   4  size mismatch (parts or assembled file; parts kept)
#   5  sha256 mismatch (assembled file and parts kept)
#   6  metadata API or mirror unavailable
#   7  another writer holds the output/parts (use --take-over) or the lock is held
#   130/143  interrupted (parts kept)
#
# EXAMPLES
#   # the normal case: inspect first, then download to ~/Downloads
#   scripts/ops/ut2004/fetch-base.sh --dry-run
#   scripts/ops/ut2004/fetch-base.sh
#
#   # continue the hand-started download, without touching its running curls
#   scripts/ops/ut2004/fetch-base.sh --dry-run \
#       --parts-dir /tmp/opencode/utparts --adopt-running
#   scripts/ops/ut2004/fetch-base.sh \
#       --parts-dir /tmp/opencode/utparts --adopt-running --take-over
#
#   # explicit legacy layout, 6 connections, verify a known sha256
#   scripts/ops/ut2004/fetch-base.sh --parallel 6 --sha256 <64-hex> \
#       --parts-dir /tmp/opencode/utparts \
#       --adopt 'p1=577169443-1070000000,p2=1070000001-1570000000,
#                p3=1570000001-2070000000,p4=2070000001-2550381121'
#
#   # a different item/file and destination
#   scripts/ops/ut2004/fetch-base.sh --item some-item --file 'some file.zip' \
#       --output /data/some-file.zip --parallel 4
set -uo pipefail

PROG="fetch-base"
ITEM="unreal-tournament-2004_202405"
FILE_NAME="Unreal Tournament 2004.zip"
OUTPUT="${HOME}/Downloads/Unreal Tournament 2004.zip"
PARALLEL=4
EXPECTED_SIZE=""
EXPECTED_SHA=""
PARTS_DIR=""
DRY_RUN=0
ADOPT_SPEC=""
ADOPT_RUNNING=0
TAKE_OVER=0
FRESH_PARTS=0
KEEP_PARTS=0
METADATA_URL=""
MIRROR_OVERRIDES=()
MAX_TIME=1800
SPEED_LIMIT=10240
SPEED_TIME=60
MAX_ATTEMPTS=12
PROBE=1
PROBE_TIMEOUT=25

say()  { printf '%s\n' "$*"; }
info() { printf '%s\n' "$*" >&2; }
warn() { printf '%s: warning: %s\n' "$PROG" "$*" >&2; }
die()  { local code=$1; shift; printf '%s: %s\n' "$PROG" "$*" >&2; exit "$code"; }

usage() {
    sed -n '2,/^set -uo pipefail$/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
    case "$1" in
        --item)          [ $# -ge 2 ] || die 2 "--item needs a value"; ITEM=$2; shift 2 ;;
        --file)          [ $# -ge 2 ] || die 2 "--file needs a value"; FILE_NAME=$2; shift 2 ;;
        --output)        [ $# -ge 2 ] || die 2 "--output needs a value"; OUTPUT=$2; shift 2 ;;
        --parts-dir)     [ $# -ge 2 ] || die 2 "--parts-dir needs a value"; PARTS_DIR=$2; shift 2 ;;
        --parallel)      [ $# -ge 2 ] || die 2 "--parallel needs a value"; PARALLEL=$2; shift 2 ;;
        --expected-size) [ $# -ge 2 ] || die 2 "--expected-size needs a value"; EXPECTED_SIZE=$2; shift 2 ;;
        --sha256)        [ $# -ge 2 ] || die 2 "--sha256 needs a value"; EXPECTED_SHA=$2; shift 2 ;;
        --dry-run)       DRY_RUN=1; shift ;;
        --adopt)         [ $# -ge 2 ] || die 2 "--adopt needs a value"; ADOPT_SPEC=$2; shift 2 ;;
        --adopt-running) ADOPT_RUNNING=1; shift ;;
        --take-over)     TAKE_OVER=1; shift ;;
        --fresh-parts)   FRESH_PARTS=1; shift ;;
        --keep-parts)    KEEP_PARTS=1; shift ;;
        --metadata-url)  [ $# -ge 2 ] || die 2 "--metadata-url needs a value"; METADATA_URL=$2; shift 2 ;;
        --mirror)        [ $# -ge 2 ] || die 2 "--mirror needs a value"; MIRROR_OVERRIDES+=("$2"); shift 2 ;;
        --max-time)      [ $# -ge 2 ] || die 2 "--max-time needs a value"; MAX_TIME=$2; shift 2 ;;
        --speed-limit)   [ $# -ge 2 ] || die 2 "--speed-limit needs a value"; SPEED_LIMIT=$2; shift 2 ;;
        --speed-time)    [ $# -ge 2 ] || die 2 "--speed-time needs a value"; SPEED_TIME=$2; shift 2 ;;
        --retries)       [ $# -ge 2 ] || die 2 "--retries needs a value"; MAX_ATTEMPTS=$2; shift 2 ;;
        --no-probe)      PROBE=0; shift ;;
        -h|--help)       usage; exit 0 ;;
        --)              shift; break ;;
        *)               die 2 "unknown argument: $1 (try --help)" ;;
    esac
done

[[ "$PARALLEL" =~ ^[0-9]+$ ]] && [ "$PARALLEL" -ge 1 ] && [ "$PARALLEL" -le 16 ] \
    || die 2 "--parallel must be 1..16"
for v in MAX_TIME SPEED_LIMIT SPEED_TIME MAX_ATTEMPTS; do
    [[ "${!v}" =~ ^[0-9]+$ ]] || die 2 "--${v,,} needs a non-negative integer"
done
[ "$MAX_TIME" -gt 0 ] || die 2 "--max-time must be > 0"
[ "$SPEED_TIME" -gt 0 ] || die 2 "--speed-time must be > 0"
[ "$MAX_ATTEMPTS" -ge 1 ] || die 2 "--retries must be >= 1"
if [ -n "$EXPECTED_SHA" ]; then
    [[ "$EXPECTED_SHA" =~ ^[0-9A-Fa-f]{64}$ ]] || die 2 "--sha256 must be 64 hex characters"
    EXPECTED_SHA=${EXPECTED_SHA,,}
fi
if [ -n "$EXPECTED_SIZE" ]; then
    [[ "$EXPECTED_SIZE" =~ ^[0-9]+$ ]] || die 2 "--expected-size must be a byte count"
fi
[ -n "$ITEM" ] && [ -n "$FILE_NAME" ] && [ -n "$OUTPUT" ] || die 2 "item, file and output must not be empty"

for tool in curl python3 sha256sum stat flock df; do
    command -v "$tool" >/dev/null 2>&1 || die 2 "missing tool: $tool"
done

OUTPUT=$(python3 -c 'import os,sys; print(os.path.abspath(sys.argv[1]))' "$OUTPUT")
[ -d "$OUTPUT" ] && die 2 "--output is a directory: $OUTPUT"
OUT_DIR=$(dirname "$OUTPUT")
[ -d "$OUT_DIR" ] || die 2 "output directory does not exist: $OUT_DIR"
[ -w "$OUT_DIR" ] || die 2 "output directory is not writable: $OUT_DIR"
if [ -z "$PARTS_DIR" ]; then PARTS_DIR="${OUTPUT}.parts"; fi
PARTS_DIR=$(python3 -c 'import os,sys; print(os.path.abspath(sys.argv[1]))' "$PARTS_DIR")
MANIFEST_FILE="$PARTS_DIR/manifest.json"
STOP_FILE="$PARTS_DIR/.stop"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/${PROG}.XXXXXX")" || die 2 "cannot create a temporary directory"
cleanup_tmp() { rm -rf "$TMP"; }
trap cleanup_tmp EXIT

[ -n "$METADATA_URL" ] || METADATA_URL="https://archive.org/metadata/${ITEM}"

# ---------------------------------------------------------------------------
# metadata + mirrors
# ---------------------------------------------------------------------------
META_FILE="$TMP/metadata.json"
info "$PROG: item $ITEM"
info "$PROG: file $FILE_NAME"
info "$PROG: reading metadata from $METADATA_URL"
if ! curl -fsSL --http1.1 --retry 3 --retry-delay 2 --connect-timeout 15 --max-time 90 \
        -o "$META_FILE" "$METADATA_URL"; then
    die 6 "metadata request failed: $METADATA_URL"
fi

META_SIZE=""; META_SHA=""; META_MD5=""; META_NAME=""
while IFS=$'\t' read -r key value; do
    case "$key" in
        size)   META_SIZE=$value ;;
        sha256) META_SHA=$value ;;
        md5)    META_MD5=$value ;;
        name)   META_NAME=$value ;;
        error)  META_ERROR=$value ;;
    esac
done < <(python3 - "$META_FILE" "$FILE_NAME" <<'PY'
import json, sys
path, wanted = sys.argv[1], sys.argv[2]
try:
    d = json.load(open(path, encoding="utf-8"))
except Exception as e:
    print("error\tmetadata is not valid JSON: %s" % e)
    raise SystemExit(0)
files = d.get("files") or []
match = next((f for f in files if f.get("name", "").lower() == wanted.lower()), None)
if match is None:
    names = [f.get("name", "") for f in files if f.get("name", "").lower().endswith(".zip")]
    print("error\tfile %r not in item; zip files there: %s" % (wanted, ", ".join(names) or "(none)"))
    raise SystemExit(0)
for key in ("size", "sha256", "md5", "name"):
    print("%s\t%s" % (key, match.get(key, "")))
for key in ("dir", "server", "d1", "d2"):
    print("%s\t%s" % (key, d.get(key, "")))
PY
)
[ -z "${META_ERROR:-}" ] || die 6 "$META_ERROR"
[ -n "$META_NAME" ] || die 6 "metadata has no usable file entry"

if [ -n "$META_SIZE" ]; then
    TOTAL=$META_SIZE
else
    TOTAL=$EXPECTED_SIZE
    [ -n "$TOTAL" ] || die 6 "metadata has no size for $META_NAME; pass --expected-size"
fi
if [ -n "$EXPECTED_SIZE" ] && [ "$EXPECTED_SIZE" != "$TOTAL" ]; then
    die 2 "metadata size $TOTAL differs from --expected-size $EXPECTED_SIZE"
fi
if [ -n "$EXPECTED_SIZE" ]; then TOTAL=$EXPECTED_SIZE; fi
[ -n "$TOTAL" ] || TOTAL=$META_SIZE
[[ "$TOTAL" =~ ^[0-9]+$ ]] && [ "$TOTAL" -gt 0 ] || die 6 "no usable positive file size (got '$TOTAL')"
if [ -z "$EXPECTED_SHA" ] && [[ "$META_SHA" =~ ^[0-9A-Fa-f]{64}$ ]]; then
    EXPECTED_SHA=${META_SHA,,}
fi

MIRRORS=()
if [ ${#MIRROR_OVERRIDES[@]} -gt 0 ]; then
    MIRRORS=("${MIRROR_OVERRIDES[@]}")
else
    while IFS= read -r url; do [ -n "$url" ] && MIRRORS+=("$url"); done < <(
        python3 - "$META_FILE" "$ITEM" "$META_NAME" <<'PY'
import json, sys, urllib.parse
d = json.load(open(sys.argv[1], encoding="utf-8"))
item, name = sys.argv[2], sys.argv[3]
dir_ = d.get("dir") or ""
qname = urllib.parse.quote(name)
seen = set()
for host in (d.get("d2"), d.get("server"), d.get("d1")):
    if host:
        u = "https://%s%s/%s" % (host, dir_, qname)
        if u not in seen:
            seen.add(u); print(u)
u = "https://archive.org/download/%s/%s" % (urllib.parse.quote(item), qname)
if u not in seen:
    print(u)
PY
    )
fi
[ ${#MIRRORS[@]} -gt 0 ] || die 6 "no mirrors to try"
# de-duplicate, keep order
DEDUP=(); for u in "${MIRRORS[@]}"; do
    dup=0; for v in "${DEDUP[@]:-}"; do [ "$u" = "$v" ] && dup=1; done
    [ "$dup" -eq 0 ] && DEDUP+=("$u")
done
MIRRORS=("${DEDUP[@]}")

probe_mirror() { # url -> "status<TAB>length<TAB>time<TAB>note"
    local url=$1 raw
    raw=$(curl -sSIL --http1.1 --connect-timeout 10 --max-time "$PROBE_TIMEOUT" \
            -D - -o /dev/null -w '\n__PROBE__ %{http_code} %{time_total}\n' "$url" 2>/dev/null) || true
    printf '%s\n' "$raw" | awk -v total="$TOTAL" '
        BEGIN { code="000"; len=""; t=""; pcode="000" }
        /^HTTP\// { code=$2 }
        tolower($1)=="content-length:" { len=$2 }
        /^__PROBE__/ { pcode=$2; t=$3 }
        END {
            gsub("\r", "", len)
            note="ok"
            if (pcode ~ /^[0-9]+$/) code=pcode
            if (code != "200" && code != "206") note="http " code
            else if (len == "" || len != total) note="content-length " (len == "" ? "missing" : len) " != " total
            printf "%s\t%s\t%s\t%s\n", code, len, t, note
        }'
}

GOOD_MIRRORS=()
if [ "$PROBE" -eq 1 ]; then
    info "$PROG: probing ${#MIRRORS[@]} mirror(s)"
    for url in "${MIRRORS[@]}"; do
        IFS=$'\t' read -r code len t note < <(probe_mirror "$url")
        if [ "$note" = ok ]; then
            GOOD_MIRRORS+=("$url")
            info "  ok   ${code} ${t}s  $url"
        else
            info "  skip ${note}  $url"
        fi
    done
    [ ${#GOOD_MIRRORS[@]} -gt 0 ] || die 6 "no mirror reports the expected size $TOTAL"
    MIRRORS=("${GOOD_MIRRORS[@]}")
else
    warn "mirror probe disabled"
fi

# ---------------------------------------------------------------------------
# prefix, layout, parts
# ---------------------------------------------------------------------------
PREFIX=0
if [ -e "$OUTPUT" ]; then
    [ -f "$OUTPUT" ] || die 2 "output is not a regular file: $OUTPUT"
    PREFIX=$(stat -c %s -- "$OUTPUT")
    if [ "$PREFIX" -gt "$TOTAL" ]; then
        die 4 "output is larger than the expected size ($PREFIX > $TOTAL): $OUTPUT"
    fi
fi

# returns RANGE lines (path<TAB>start<TAB>end<TAB>length) or ERR<TAB>message
load_manifest() {
    python3 - "$MANIFEST_FILE" "$ITEM" "$META_NAME" "$TOTAL" "$PREFIX" <<'PY'
import json, os, sys
path, item, name, total, prefix = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4]), int(sys.argv[5])
try:
    d = json.load(open(path, encoding="utf-8"))
except Exception as e:
    print("ERR\tcannot read %s: %s" % (path, e)); raise SystemExit(0)
if d.get("version") != 1:
    print("ERR\tunsupported manifest version %r" % d.get("version")); raise SystemExit(0)
if d.get("item") != item or d.get("file") != name:
    print("ERR\tmanifest belongs to %r/%r, not %r/%r" % (d.get("item"), d.get("file"), item, name)); raise SystemExit(0)
if int(d.get("total", -1)) != total:
    print("ERR\tmanifest total %s != %s (use another --parts-dir or --fresh-parts)" % (d.get("total"), total)); raise SystemExit(0)
if int(d.get("prefix", -1)) != prefix:
    print("ERR\tmanifest prefix %s != current output size %s; the output changed since the plan" % (d.get("prefix"), prefix)); raise SystemExit(0)
ranges = d.get("ranges") or []
if not ranges:
    print("ERR\tmanifest has no ranges"); raise SystemExit(0)
prev = None
for r in ranges:
    p = os.path.basename(str(r.get("path", "")))
    try:
        s, e, l = int(r["start"]), int(r["end"]), int(r["length"])
    except Exception:
        print("ERR\tmanifest range is malformed"); raise SystemExit(0)
    if not p or e < s or e - s + 1 != l:
        print("ERR\tmanifest range %r is inconsistent" % p); raise SystemExit(0)
    if prev is None and s != prefix:
        print("ERR\tfirst range starts at %d, expected the prefix size %d" % (s, prefix)); raise SystemExit(0)
    if prev is not None and s != prev + 1:
        print("ERR\tranges are not contiguous at %d" % s); raise SystemExit(0)
    prev = e
    print("RANGE\t%s\t%d\t%d\t%d" % (p, s, e, l))
if prev != total - 1:
    print("ERR\tlast range ends at %s, expected %s" % (prev, total - 1)); raise SystemExit(0)
PY
}

adopt_spec() {
    python3 - "$PARTS_DIR" "$PREFIX" "$TOTAL" "$ADOPT_SPEC" <<'PY'
import os, re, sys
partsdir, prefix, total, spec = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), sys.argv[4]
rows = []
for chunk in spec.split(","):
    chunk = chunk.strip()
    if not chunk:
        continue
    if "=" not in chunk:
        print("ERR\tbad --adopt item %r (want name=start-end)" % chunk); raise SystemExit(0)
    name, rng = chunk.rsplit("=", 1)
    name = os.path.basename(name.strip())
    m = re.match(r"^(\d+)-(\d+)$", rng.strip())
    if not m:
        print("ERR\tbad range %r for %s (want start-end)" % (rng, name)); raise SystemExit(0)
    s, e = int(m.group(1)), int(m.group(2))
    path = os.path.join(partsdir, name)
    if not os.path.isfile(path):
        print("ERR\tadopted part does not exist: %s" % path); raise SystemExit(0)
    rows.append((s, e, name))
if not rows:
    print("ERR\t--adopt is empty"); raise SystemExit(0)
rows.sort()
prev = None
for s, e, name in rows:
    if e < s or e >= total:
        print("ERR\trange %s=%d-%d is outside the file" % (name, s, e)); raise SystemExit(0)
    if prev is None and s != prefix:
        print("ERR\tfirst adopted range starts at %d, expected prefix size %d" % (s, prefix)); raise SystemExit(0)
    if prev is not None and s != prev + 1:
        print("ERR\tadopted ranges are not contiguous at %d" % s); raise SystemExit(0)
    prev = e
    print("RANGE\t%s\t%d\t%d\t%d" % (name, s, e, e - s + 1))
if prev != total - 1:
    print("ERR\tadopted ranges end at %s, expected %s" % (prev, total - 1)); raise SystemExit(0)
PY
}

adopt_running() {
    python3 - "$PARTS_DIR" "$PREFIX" "$TOTAL" <<'PY'
import glob, os, re, sys
partsdir = os.path.realpath(sys.argv[1])
prefix, total = int(sys.argv[2]), int(sys.argv[3])
found = {}

def consider(path, start, end):
    path = os.path.realpath(path)
    if os.path.dirname(path) != partsdir:
        return
    found.setdefault(path, (start, end))

for cmdline in glob.glob("/proc/[0-9]*/cmdline"):
    try:
        raw = open(cmdline, "rb").read()
    except OSError:
        continue
    args = [a.decode("utf-8", "replace") for a in raw.split(b"\0") if a]
    if args and os.path.basename(args[0]) == "curl":
        # direct curl process: reliable, argument by argument
        out = rng = None
        for i, a in enumerate(args[:-1]):
            if a in ("-o", "--output"):
                out = args[i + 1]
            elif a in ("-r", "--range"):
                rng = args[i + 1]
        if out and rng:
            m = re.match(r"^(\d+)-(\d+)$", rng.strip())
            if m:
                consider(out, int(m.group(1)), int(m.group(2)))
        continue
    # A shell wrapper such as `bash -c '... curl -r A-B ... -o p1 ...'` keeps the whole
    # command line in one argv entry. Fall back to a textual scan so that ranges of curls
    # that already finished (but whose wrapper still waits) are adopted too.
    text = " ".join(args)
    if "curl" not in text:
        continue
    for m in re.finditer(r"-r\s+(\d+)-(\d+)([^;&|]*?)-o\s+([^\s;&|]+)", text):
        path = m.group(4).strip("'\"")
        consider(path, int(m.group(1)), int(m.group(2)))
if not found:
    print("ERR\tno running curl with -r/-o found for %s" % partsdir); raise SystemExit(0)
rows = sorted((s, e, os.path.basename(p), p) for p, (s, e) in found.items())
prev = None
for s, e, name, path in rows:
    if e < s or e >= total:
        print("ERR\trunning range %s=%d-%d is outside the file" % (name, s, e)); raise SystemExit(0)
    if prev is None and s != prefix:
        print("ERR\tfirst running range starts at %d, expected prefix size %d" % (s, prefix)); raise SystemExit(0)
    if prev is not None and s != prev + 1:
        print("ERR\trunning ranges are not contiguous at %d" % s); raise SystemExit(0)
    prev = e
    print("RANGE\t%s\t%d\t%d\t%d" % (name, s, e, e - s + 1))
if prev != total - 1:
    print("ERR\trunning ranges end at %s, expected %s" % (prev, total - 1)); raise SystemExit(0)
PY
}

adopt_filenames() {
    python3 - "$PARTS_DIR" "$PREFIX" "$TOTAL" <<'PY'
import glob, os, re, sys
partsdir, prefix, total = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
rows = []
for path in glob.glob(os.path.join(partsdir, "part-*-*.bin")):
    m = re.match(r"^part-\d+-(\d+)\.bin$", os.path.basename(path))
    if m:
        rows.append((int(m.group(1)), path))
if not rows:
    print("ERR\tno range-named part files in %s" % partsdir); raise SystemExit(0)
rows.sort()
if rows[0][0] != prefix:
    print("ERR\tfirst part starts at %d, expected prefix %d" % (rows[0][0], prefix)); raise SystemExit(0)
for i, (start, path) in enumerate(rows):
    end = (rows[i + 1][0] - 1) if i + 1 < len(rows) else total - 1
    if end < start:
        print("ERR\tpart starts overlap at %s" % path); raise SystemExit(0)
    print("RANGE\t%s\t%d\t%d\t%d" % (os.path.basename(path), start, end, end - start + 1))
PY
}

plan_ranges() {
    python3 - "$PREFIX" "$TOTAL" "$PARALLEL" <<'PY'
import sys
prefix, total, par = int(sys.argv[1]), int(sys.argv[2]), int(sys.argv[3])
remaining = total - prefix
if remaining <= 0:
    raise SystemExit(0)
n = min(par, remaining) if remaining < par else par
for i in range(n):
    start = prefix + (remaining * i) // n
    end = prefix + (remaining * (i + 1)) // n - 1
    print("RANGE\tpart-%02d-%010d.bin\t%d\t%d\t%d" % (i, start, start, end, end - start + 1))
PY
}

write_manifest() {
    # $1 = prefix, $2 = TSV file with the RANGE lines (stdin is the Python heredoc)
    python3 - "$MANIFEST_FILE" "$ITEM" "$META_NAME" "$TOTAL" "$1" "$EXPECTED_SHA" "$2" <<'PY'
import json, os, sys, time
dest, item, name, total, prefix, sha, tsv = sys.argv[1:8]
ranges = []
with open(tsv, encoding="utf-8") as fh:
    for line in fh:
        parts = line.rstrip("\n").split("\t")
        if len(parts) != 5 or parts[0] != "RANGE":
            continue
        ranges.append({"path": parts[1], "start": int(parts[2]), "end": int(parts[3]),
                       "length": int(parts[4])})
out = {"version": 1, "item": item, "file": name, "total": int(total),
       "prefix": int(prefix), "sha256": sha, "created": int(time.time()),
       "ranges": ranges}
tmp = dest + ".tmp"
with open(tmp, "w", encoding="utf-8") as fh:
    json.dump(out, fh, indent=2)
    fh.write("\n")
os.replace(tmp, dest)
PY
}

RANGES=""
LAYOUT=""
if [ "$PREFIX" -eq "$TOTAL" ]; then
    LAYOUT="complete output"
elif [ "$FRESH_PARTS" -eq 1 ]; then
    LAYOUT="fresh plan"
elif [ -f "$MANIFEST_FILE" ]; then
    RANGES=$(load_manifest)
    LAYOUT="manifest"
elif [ -n "$ADOPT_SPEC" ]; then
    RANGES=$(adopt_spec)
    LAYOUT="adopted layout"
elif [ "$ADOPT_RUNNING" -eq 1 ]; then
    RANGES=$(adopt_running)
    LAYOUT="running curls"
elif [ -d "$PARTS_DIR" ] && [ -n "$(find "$PARTS_DIR" -maxdepth 1 -type f -name 'part-*-*.bin' -print -quit)" ]; then
    RANGES=$(adopt_filenames)
    LAYOUT="part-file names"
fi

if printf '%s\n' "$RANGES" | grep -q '^ERR'; then
    die 2 "$(printf '%s\n' "$RANGES" | sed -n 's/^ERR\t//p' | head -1)"
fi

if [ -z "$RANGES" ] && [ "$PREFIX" -ne "$TOTAL" ]; then
    if [ -d "$PARTS_DIR" ]; then
        UNKNOWN=$(find "$PARTS_DIR" -maxdepth 1 -type f ! -name 'manifest.json' ! -name '.stop' \
                    ! -name '.lock' ! -name '.*.tmp' -print -quit)
        if [ -n "$UNKNOWN" ]; then
            if [ "$FRESH_PARTS" -eq 1 ]; then
                LEGACY="$PARTS_DIR/legacy-$(date +%Y%m%d-%H%M%S)"
                if [ "$DRY_RUN" -eq 1 ]; then
                    warn "dry run: would move unrecognised part files to $LEGACY"
                else
                    mkdir -p "$LEGACY" || die 2 "cannot create $LEGACY"
                    find "$PARTS_DIR" -maxdepth 1 -type f ! -name 'manifest.json' ! -name '.stop' \
                        ! -name '.lock' -exec mv -t "$LEGACY" -- {} + || die 2 "cannot move old parts to $LEGACY"
                    warn "moved unrecognised part files to $LEGACY"
                fi
            else
                die 2 "unrecognised files in $PARTS_DIR (sizes alone cannot identify their ranges); use --adopt, --adopt-running or --fresh-parts"
            fi
        fi
    fi
    RANGES=$(plan_ranges)
    LAYOUT="new plan"
fi

RANGES=$(printf '%s\n' "$RANGES" | grep '^RANGE' || true)

# arrays
P_NAME=(); P_START=(); P_END=(); P_LEN=(); P_SIZE=(); P_STATE=()
while IFS=$'\t' read -r tag path start end len; do
    [ "$tag" = RANGE ] || continue
    idx=${#P_NAME[@]}
    P_NAME[$idx]=$path; P_START[$idx]=$start; P_END[$idx]=$end; P_LEN[$idx]=$len; P_SIZE[$idx]=0
    P_STATE[$idx]="pending"
done <<< "$RANGES"

for ((i=0; i<${#P_NAME[@]}; i++)); do
    path="$PARTS_DIR/${P_NAME[$i]}"
    if [ -f "$path" ]; then
        sz=$(stat -c %s -- "$path")
        if [ "$sz" -gt "${P_LEN[$i]}" ]; then
            die 4 "part ${P_NAME[$i]} has $sz bytes, its range only allows ${P_LEN[$i]}"
        fi
        P_SIZE[$i]=$sz
    fi
done

# writers holding our files (write mode only), excluding ourselves
detect_writers() {
    python3 - "$@" <<'PY'
import os, sys
targets = {os.path.realpath(p) for p in sys.argv[1:]}
me = os.getpid()
seen = {}
for pid in os.listdir("/proc"):
    if not pid.isdigit() or int(pid) == me:
        continue
    d = "/proc/%s/fd" % pid
    try:
        fds = os.listdir(d)
    except OSError:
        continue
    for fd in fds:
        try:
            target = os.path.realpath(os.path.join(d, fd))
        except OSError:
            continue
        if target not in targets:
            continue
        try:
            flags = None
            with open("/proc/%s/fdinfo/%s" % (pid, fd)) as fh:
                for line in fh:
                    if line.startswith("flags:"):
                        flags = int(line.split()[1], 8); break
            if flags is not None and (flags & 3) == 0:
                continue
        except (OSError, ValueError):
            pass
        seen[target] = int(pid)
if seen:
    for target, pid in sorted(seen.items()):
        print("%d\t%s" % (pid, target))
PY
}

WRITER_PIDS=()
if [ ${#P_NAME[@]} -gt 0 ] || [ -e "$OUTPUT" ]; then
    WRITER_PATHS=()
    for ((i=0; i<${#P_NAME[@]}; i++)); do WRITER_PATHS+=("$PARTS_DIR/${P_NAME[$i]}"); done
    [ -e "$OUTPUT" ] && WRITER_PATHS+=("$OUTPUT")
    while IFS=$'\t' read -r pid path; do
        [ -n "${pid:-}" ] && WRITER_PIDS+=("$pid") && info "  writer: pid $pid -> $path"
    done < <(detect_writers "${WRITER_PATHS[@]}")
fi

if [ ${#WRITER_PIDS[@]} -gt 0 ]; then
    if [ "$TAKE_OVER" -eq 1 ] && [ "$DRY_RUN" -eq 0 ]; then
        info "$PROG: stopping ${#WRITER_PIDS[@]} writer(s): ${WRITER_PIDS[*]}"
        kill -TERM "${WRITER_PIDS[@]}" 2>/dev/null || true
        for _ in $(seq 1 50); do
            alive=0
            for pid in "${WRITER_PIDS[@]}"; do kill -0 "$pid" 2>/dev/null && alive=1; done
            [ "$alive" -eq 0 ] && break
            sleep 0.2
        done
        for pid in "${WRITER_PIDS[@]}"; do kill -0 "$pid" 2>/dev/null && kill -KILL "$pid" 2>/dev/null; done
        sleep 0.3
        # sizes may have moved while the writer was finishing
        for ((i=0; i<${#P_NAME[@]}; i++)); do
            path="$PARTS_DIR/${P_NAME[$i]}"
            [ -f "$path" ] && P_SIZE[$i]=$(stat -c %s -- "$path")
        done
        if [ -n "$(detect_writers "${WRITER_PATHS[@]}")" ]; then
            die 7 "writers are still holding the files after --take-over"
        fi
    elif [ "$DRY_RUN" -eq 1 ]; then
        warn "active writer(s); a real run would refuse (exit 7) unless --take-over is used"
    else
        die 7 "another process is writing the output/parts (pids ${WRITER_PIDS[*]}); wait for it or use --take-over"
    fi
fi

if [ "$DRY_RUN" -eq 0 ] && [ "$PREFIX" -ne "$TOTAL" ]; then
    mkdir -p "$PARTS_DIR" || die 2 "cannot create $PARTS_DIR"
    exec 9>"$PARTS_DIR/.lock" || die 2 "cannot open lock file"
    flock -n 9 || die 7 "another $PROG is already using $PARTS_DIR"
    if [ -n "$RANGES" ]; then
        printf '%s\n' "$RANGES" > "$TMP/ranges.tsv" || die 2 "cannot write $TMP/ranges.tsv"
        write_manifest "$PREFIX" "$TMP/ranges.tsv" || die 2 "cannot write $MANIFEST_FILE"
    fi
fi

# ---------------------------------------------------------------------------
# reporting
# ---------------------------------------------------------------------------
fmt_bytes() {
    awk -v b="${1:-0}" 'BEGIN { split("B KiB MiB GiB TiB", u, " "); i=1; while (b >= 1024 && i < 5) { b/=1024; i++ } if (i == 1) printf "%.0f %s", b, u[i]; else printf "%.1f %s", b, u[i] }'
}
fmt_dur() {
    awk -v s="${1:-0}" 'BEGIN { s=int(s); if (s < 0) s=0; if (s < 60) printf "%02ds", s; else if (s < 3600) printf "%02dm%02ds", int(s/60), s%60; else printf "%dh%02dm", int(s/3600), int((s%3600)/60) }'
}
pct() { awk -v a="$1" -v b="$2" 'BEGIN { if (b > 0) printf "%.1f%%", 100*a/b; else printf "n/a" }'; }

sum_parts() {
    local total=0 sz
    for ((i=0; i<${#P_NAME[@]}; i++)); do
        if [ -f "$PARTS_DIR/${P_NAME[$i]}" ]; then
            sz=$(stat -c %s -- "$PARTS_DIR/${P_NAME[$i]}" 2>/dev/null || echo 0)
            P_SIZE[$i]=$sz
            total=$((total + sz))
        fi
    done
    echo "$total"
}

say ""
say "item:        $ITEM"
say "file:        $META_NAME"
say "size:        $TOTAL bytes ($(fmt_bytes "$TOTAL"))"
if [ -n "$EXPECTED_SHA" ]; then
    say "sha256:      $EXPECTED_SHA"
elif [ -n "$META_MD5" ]; then
    say "sha256:      not published (metadata md5: $META_MD5; not checked)"
else
    say "sha256:      not published; not checked"
fi
say "output:      $OUTPUT"
say "prefix:      $PREFIX bytes ($(pct "$PREFIX" "$TOTAL"))"
say "parts dir:   $PARTS_DIR"
say "layout:      $LAYOUT"
say "mirrors:     ${#MIRRORS[@]} usable"
for url in "${MIRRORS[@]}"; do say "  - $url"; done
say "parts:       ${#P_NAME[@]}"
DONE_BYTES=$PREFIX
for ((i=0; i<${#P_NAME[@]}; i++)); do
    have=${P_SIZE[$i]}
    DONE_BYTES=$((DONE_BYTES + have))
    state="pending"
    [ "$have" -eq "${P_LEN[$i]}" ] && state="complete"
    [ "$have" -gt 0 ] && [ "$have" -lt "${P_LEN[$i]}" ] && state="resume at $((P_START[i] + have))"
    say "  ${P_NAME[$i]}  [${P_START[$i]}..${P_END[$i]}]  $(fmt_bytes "${P_LEN[$i]}")  have $(fmt_bytes "$have")  $state"
done
say "have:        $DONE_BYTES / $TOTAL bytes ($(pct "$DONE_BYTES" "$TOTAL"))"
say "remaining:   $((TOTAL - DONE_BYTES)) bytes ($(fmt_bytes $((TOTAL - DONE_BYTES))))"

if [ "$DRY_RUN" -eq 1 ]; then
    say ""
    say "dry run: no data written."
    exit 0
fi

if [ "$PREFIX" -eq "$TOTAL" ] && [ ${#P_NAME[@]} -eq 0 ]; then
    if [ -n "$EXPECTED_SHA" ]; then
        info "$PROG: verifying sha256 of the existing output"
        got=$(sha256sum -- "$OUTPUT" | awk '{print $1}')
        [ "$got" = "$EXPECTED_SHA" ] || die 5 "sha256 mismatch: got $got, expected $EXPECTED_SHA"
    fi
    say "already complete: $OUTPUT"
    rmdir "$PARTS_DIR" 2>/dev/null || true
    exit 0
fi

# ---------------------------------------------------------------------------
# downloader
# ---------------------------------------------------------------------------
PART_PID=()

part_log() {
    printf '%s %s\n' "$(date +%H:%M:%S)" "$*" >&2
}

validate_tail() { # hdr tail expected_start requested_end -> "ok<TAB>bytes" | "bad<TAB>reason"
    python3 - "$1" "$2" "$3" "$4" "$TOTAL" <<'PY'
import os, re, sys
hdr, tail, start, end, total = sys.argv[1], sys.argv[2], int(sys.argv[3]), int(sys.argv[4]), int(sys.argv[5])
try:
    raw = open(hdr, "rb").read().decode("latin1")
except OSError:
    print("bad\theader file missing"); raise SystemExit(0)
status = None
cr = None
for line in reversed(raw.splitlines()):
    if status is None:
        m = re.match(r"^HTTP/\S+\s+(\d+)", line)
        if m:
            status = int(m.group(1))
    if cr is None and line.lower().startswith("content-range:"):
        cr = line.split(":", 1)[1].strip()
    if status is not None and cr is not None:
        break
if status != 206:
    print("bad\thttp %s (not 206)" % status); raise SystemExit(0)
m = re.match(r"bytes\s+(\d+)-(\d+)/(\d+|\*)", cr or "")
if not m:
    print("bad\tmissing/invalid Content-Range %r" % (cr,)); raise SystemExit(0)
a, b, t = int(m.group(1)), int(m.group(2)), m.group(3)
if a != start:
    print("bad\tContent-Range starts at %d, requested %d" % (a, start)); raise SystemExit(0)
if b > end:
    print("bad\tContent-Range ends at %d, beyond requested %d" % (b, end)); raise SystemExit(0)
if t != "*" and int(t) != total:
    print("bad\tContent-Range total %s != %d" % (t, total)); raise SystemExit(0)
size = os.path.getsize(tail) if os.path.exists(tail) else 0
print("ok\t%d" % size)
PY
}

download_part() {
    local idx=$1
    local start=${P_START[$idx]} end=${P_END[$idx]} len=${P_LEN[$idx]}
    local part="$PARTS_DIR/${P_NAME[$idx]}"
    local cur attempt=0 rc url abs_start tail hdr mcount=${#MIRRORS[@]}
    cur=${P_SIZE[$idx]}
    local tail_file="$TMP/tail.$idx" hdr_file="$TMP/hdr.$idx"
    local curlv="" part_stop=0
    trap 'part_stop=1; [ -n "${curlv:-}" ] && kill -TERM "$curlv" 2>/dev/null' TERM INT
    part_log "part ${P_NAME[$idx]}: start=$start end=$end have=$cur len=$len"
    while [ "$cur" -lt "$len" ]; do
        if [ -e "$STOP_FILE" ] || [ "$part_stop" -eq 1 ]; then break; fi
        attempt=$((attempt + 1))
        if [ "$attempt" -gt "$MAX_ATTEMPTS" ]; then
            part_log "part ${P_NAME[$idx]}: giving up after $MAX_ATTEMPTS attempts"
            return 3
        fi
        url=${MIRRORS[$(( (attempt + idx) % mcount ))]}
        abs_start=$((start + cur))
        : > "$tail_file"
        : > "$hdr_file"
        part_log "part ${P_NAME[$idx]}: attempt $attempt range $abs_start-$end mirror $url"
        curl --http1.1 -fL --silent --show-error \
            --connect-timeout 20 --max-time "$MAX_TIME" \
            --speed-limit "$SPEED_LIMIT" --speed-time "$SPEED_TIME" \
            -r "$abs_start-$end" -D "$hdr_file" -o "$tail_file" "$url" &
        curlv=$!
        printf '%s\n' "$curlv" > "$TMP/curl.$idx.pid"
        wait "$curlv"; rc=$?
        curlv=""
        rm -f "$TMP/curl.$idx.pid"
        IFS=$'\t' read -r verdict detail < <(validate_tail "$hdr_file" "$tail_file" "$abs_start" "$end")
        if [ "$verdict" = ok ]; then
            got=$(stat -c %s -- "$tail_file" 2>/dev/null || echo 0)
            if [ "$got" -gt 0 ]; then
                cat -- "$tail_file" >> "$part" || return 3
                cur=$((cur + got))
                part_log "part ${P_NAME[$idx]}: +$got bytes (curl rc=$rc), now $cur/$len"
            else
                part_log "part ${P_NAME[$idx]}: empty response, curl rc=$rc"
            fi
        else
            part_log "part ${P_NAME[$idx]}: rejected response: $detail (curl rc=$rc)"
        fi
        rm -f "$tail_file" "$hdr_file"
        if [ -e "$STOP_FILE" ] || [ "$part_stop" -eq 1 ]; then break; fi
        if [ "$cur" -lt "$len" ]; then
            sleep_for=$(( attempt < 5 ? attempt * 2 : 10 ))
            sleep "$sleep_for" & wait $! 2>/dev/null || true
        fi
    done
    if [ "$cur" -ge "$len" ]; then
        part_log "part ${P_NAME[$idx]}: complete ($len bytes)"
        return 0
    fi
    part_log "part ${P_NAME[$idx]}: stopped at $cur/$len (parts kept)"
    return 130
}

stop_children() {
    touch "$STOP_FILE" 2>/dev/null || true
    for pid in "${PART_PID[@]:-}"; do [ -n "$pid" ] && kill -TERM "$pid" 2>/dev/null; done
    for f in "$TMP"/curl.*.pid; do
        [ -f "$f" ] || continue
        pid=$(cat "$f" 2>/dev/null || true)
        [ -n "$pid" ] && kill -TERM "$pid" 2>/dev/null
    done
    for _ in $(seq 1 60); do
        alive=0
        for pid in "${PART_PID[@]:-}"; do [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null && alive=1; done
        [ "$alive" -eq 0 ] && break
        sleep 0.2
    done
    for pid in "${PART_PID[@]:-}"; do [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null && kill -KILL "$pid" 2>/dev/null; done
}

on_signal() {
    local code=${1:-130}
    trap - INT TERM
    printf '\n' >&2
    warn "interrupted; stopping curl and keeping parts"
    stop_children
    wait 2>/dev/null || true
    rm -f "$STOP_FILE"
    printf '%s: stopped; parts are in %s\n' "$PROG" "$PARTS_DIR" >&2
    exit "$code"
}
trap 'on_signal 130' INT
trap 'on_signal 143' TERM

if [ ${#P_NAME[@]} -eq 0 ]; then
    # nothing to download, but prefix may already be the whole file
    if [ "$PREFIX" -ne "$TOTAL" ]; then die 4 "no ranges and prefix $PREFIX != total $TOTAL"; fi
else
    info "$PROG: downloading $((TOTAL - DONE_BYTES)) bytes in ${#P_NAME[@]} range(s), parallel $PARALLEL"
    rm -f "$STOP_FILE"
    for ((i=0; i<${#P_NAME[@]}; i++)); do
        if [ "${P_SIZE[$i]}" -eq "${P_LEN[$i]}" ]; then
            info "  ${P_NAME[$i]}: already complete"
            P_STATE[$i]="done"
            continue
        fi
        download_part "$i" >>"$PARTS_DIR/${P_NAME[$i]}.log" 2>&1 &
        PART_PID[$i]=$!
        P_STATE[$i]="running"
    done

    prev_done=-1; prev_time=$(date +%s); last_print=0
    while :; do
        done_bytes=0
        running=0
        for ((i=0; i<${#P_NAME[@]}; i++)); do
            if [ "${P_STATE[$i]}" = running ]; then
                pid=${PART_PID[$i]}
                if jobs -pr | grep -qx "$pid"; then
                    running=$((running + 1))
                else
                    wait "$pid"; rc=$?
                    P_STATE[$i]="rc=$rc"
                    [ "$rc" -ne 0 ] && warn "part ${P_NAME[$i]} failed with rc=$rc"
                fi
            fi
            if [ -f "$PARTS_DIR/${P_NAME[$i]}" ]; then
                P_SIZE[$i]=$(stat -c %s -- "$PARTS_DIR/${P_NAME[$i]}" 2>/dev/null || echo 0)
            fi
            done_bytes=$((done_bytes + P_SIZE[$i]))
        done
        done_bytes=$((PREFIX + done_bytes))
        now=$(date +%s)
        elapsed=$((now - prev_time))
        if [ "$prev_done" -ge 0 ] && [ "$elapsed" -gt 0 ]; then
            speed=$(( (done_bytes - prev_done) / elapsed ))
        else
            speed=0
        fi
        [ "$speed" -lt 0 ] && speed=0
        remaining=$((TOTAL - done_bytes))
        eta=0; [ "$speed" -gt 0 ] && eta=$((remaining / speed))
        if [ -t 2 ]; then
            printf '\r\033[K[%s] %s / %s  %s/s  ETA %s  parts %d/%d' \
                "$(pct "$done_bytes" "$TOTAL")" "$(fmt_bytes "$done_bytes")" "$(fmt_bytes "$TOTAL")" \
                "$(fmt_bytes "$speed")" "$(fmt_dur "$eta")" \
                "$(( ${#P_NAME[@]} - running ))" "${#P_NAME[@]}" >&2
        elif [ $((now - last_print)) -ge 15 ]; then
            printf '[%s] %s / %s  %s/s  ETA %s\n' \
                "$(pct "$done_bytes" "$TOTAL")" "$(fmt_bytes "$done_bytes")" "$(fmt_bytes "$TOTAL")" \
                "$(fmt_bytes "$speed")" "$(fmt_dur "$eta")" >&2
            last_print=$now
        fi
        prev_done=$done_bytes; prev_time=$now
        [ "$running" -eq 0 ] && break
        sleep 2
    done
    [ -t 2 ] && printf '\n' >&2

    failed=0
    for ((i=0; i<${#P_NAME[@]}; i++)); do
        [ "${P_STATE[$i]}" = done ] && continue
        [ "${P_STATE[$i]}" = "rc=0" ] || failed=1
    done
    [ "$failed" -eq 0 ] || die 3 "at least one range failed; parts are in $PARTS_DIR"
fi

# ---------------------------------------------------------------------------
# verify + assemble
# ---------------------------------------------------------------------------
for ((i=0; i<${#P_NAME[@]}; i++)); do
    path="$PARTS_DIR/${P_NAME[$i]}"
    [ -f "$path" ] || die 4 "missing part: $path"
    sz=$(stat -c %s -- "$path")
    [ "$sz" -eq "${P_LEN[$i]}" ] || die 4 "part ${P_NAME[$i]} is $sz bytes, expected ${P_LEN[$i]}"
done
if [ "$PREFIX" -gt 0 ] && [ ! -f "$OUTPUT" ]; then
    die 4 "prefix disappeared while downloading: $OUTPUT"
fi
if [ "$PREFIX" -gt 0 ]; then
    now_prefix=$(stat -c %s -- "$OUTPUT")
    [ "$now_prefix" -eq "$PREFIX" ] || die 4 "output changed during download ($now_prefix != $PREFIX): $OUTPUT"
fi

avail_kb=$(df -Pk -- "$OUT_DIR" | awk 'NR==2 {print $4}')
if [ -n "$avail_kb" ] && [ $((avail_kb * 1024)) -lt "$TOTAL" ]; then
    die 4 "not enough free space in $OUT_DIR for assembly ($((avail_kb * 1024)) < $TOTAL bytes)"
fi

TMP_OUT="$OUTPUT.tmp.$$"
info "$PROG: assembling $TOTAL bytes -> $TMP_OUT"
: > "$TMP_OUT" || die 2 "cannot create $TMP_OUT"
if [ "$PREFIX" -gt 0 ]; then
    cat -- "$OUTPUT" >> "$TMP_OUT" || { rm -f "$TMP_OUT"; die 2 "cannot read prefix $OUTPUT"; }
fi
for ((i=0; i<${#P_NAME[@]}; i++)); do
    cat -- "$PARTS_DIR/${P_NAME[$i]}" >> "$TMP_OUT" || { rm -f "$TMP_OUT"; die 2 "cannot append ${P_NAME[$i]}"; }
done
got_size=$(stat -c %s -- "$TMP_OUT")
if [ "$got_size" -ne "$TOTAL" ]; then
    rm -f "$TMP_OUT"
    die 4 "assembled size $got_size != expected $TOTAL (parts kept)"
fi

if [ -n "$EXPECTED_SHA" ]; then
    info "$PROG: verifying sha256 (may take a while)"
    got_sha=$(sha256sum -- "$TMP_OUT" | awk '{print $1}')
    if [ "$got_sha" != "$EXPECTED_SHA" ]; then
        die 5 "sha256 mismatch: got $got_sha, expected $EXPECTED_SHA (assembled file and parts kept: $TMP_OUT)"
    fi
fi

mv -f -- "$TMP_OUT" "$OUTPUT" || die 2 "cannot rename $TMP_OUT to $OUTPUT"
say ""
say "complete: $OUTPUT"
say "size:     $TOTAL bytes ($(fmt_bytes "$TOTAL"))"
if [ -n "$EXPECTED_SHA" ]; then say "sha256:   $EXPECTED_SHA (verified)"; fi

if [ "$KEEP_PARTS" -eq 0 ]; then
    for ((i=0; i<${#P_NAME[@]}; i++)); do
        rm -f -- "$PARTS_DIR/${P_NAME[$i]}" "$PARTS_DIR/${P_NAME[$i]}.log"
    done
    rm -f -- "$MANIFEST_FILE" "$PARTS_DIR/.lock" "$STOP_FILE" "$TMP"/tail.* "$TMP"/hdr.* "$TMP"/curl.*.pid
    rmdir "$PARTS_DIR" 2>/dev/null || true
    info "$PROG: removed temporary part files"
else
    info "$PROG: kept parts in $PARTS_DIR (--keep-parts)"
fi
exit 0
