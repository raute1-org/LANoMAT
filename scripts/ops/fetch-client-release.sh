#!/usr/bin/env bash
# Fetch the native voice client files that the GitHub workflow `release-client.yml` built (Linux AND
# Windows) for the version of THIS checkout, verify them, and publish them into clients-dist/, where
# the app serves them on /talk/client (compose mounts ./clients-dist read-only).
#
#   scripts/ops/fetch-client-release.sh                     # newest successful run for this checkout's commit
#   scripts/ops/fetch-client-release.sh --run <id>          # a specific workflow run (artifact client-release-files)
#   scripts/ops/fetch-client-release.sh --tag client-v...   # the (draft or published) release of that tag
#   scripts/ops/fetch-client-release.sh --dry-run           # download and verify, publish nothing
#   scripts/ops/fetch-client-release.sh --out DIR           # publish elsewhere (default: clients-dist)
#   scripts/ops/fetch-client-release.sh --prefer-workflow   # the workflow's file replaces a local one of the same os/arch/kind
#   scripts/ops/fetch-client-release.sh --require-signed    # refuse files whose manifest entry says signed:false
#   scripts/ops/fetch-client-release.sh --allow-unsigned    # the default; unsigned files are named in the output
#   scripts/ops/fetch-client-release.sh --version V --commit C   # override what is wanted (testing; default: this checkout)
#
# Contract (docs/infra/native-client-release.md): the manifest of the fetched set must carry the
# wanted `app_version` AND `commit`; every listed name is a plain, expected file name; every file's
# size and sha256 match the manifest AND `SHA256SUMS`. Otherwise nothing is published.
#
# Publishing is atomic exactly like build-client.sh: staging dir inside the target (same filesystem),
# files first, manifest.json renamed LAST, under the same flock, then older files are pruned. The
# directory itself is never replaced (it is bind-mounted).
#
# MERGE: if the target already holds a manifest for the same version and commit (for example a locally
# built Linux client) its entries stay, the Windows entries are added; same name or same
# os/arch/kind/variant (variant: installer | portable | archive, absent = archive): the local file wins unless --prefer-workflow. Another version or commit: the old
# files go.
#
# Needs `gh` (authenticated: `gh auth login`, or GH_TOKEN in the environment; the token is never
# printed), jq, sha256sum, flock. Works without a TTY.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=lib/version.sh
source "$ROOT/scripts/ops/lib/version.sh"

ARTIFACT="client-release-files"
WORKFLOW="release-client.yml"
NAME_RE='^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'

RUN_ID=""
TAG=""
OUT="$ROOT/clients-dist"
DRY=0
PREFER_WORKFLOW=0
REQUIRE_SIGNED=0
WANT_VERSION=""
WANT_COMMIT=""

die() { echo "fetch-client-release: $*" >&2; exit 1; }
note() { echo "    $*"; }

while [ $# -gt 0 ]; do
    case "$1" in
        --run) shift; RUN_ID="${1:?--run needs a run id}" ;;
        --tag) shift; TAG="${1:?--tag needs a release tag}" ;;
        --out) shift; OUT="${1:?--out needs a directory}" ;;
        --version) shift; WANT_VERSION="${1:?--version needs a value}" ;;
        --commit) shift; WANT_COMMIT="${1:?--commit needs a value}" ;;
        --dry-run) DRY=1 ;;
        --prefer-workflow) PREFER_WORKFLOW=1 ;;
        --allow-unsigned) REQUIRE_SIGNED=0 ;;
        --require-signed) REQUIRE_SIGNED=1 ;;
        -h|--help) sed -n '2,31p' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) die "unknown argument: $1 (see --help)" ;;
    esac
    shift
done
if [ -n "$RUN_ID" ] && [ -n "$TAG" ]; then die "--run and --tag exclude each other"; fi
if [ -n "$RUN_ID" ] && ! [[ "$RUN_ID" =~ ^[0-9]+$ ]]; then die "--run needs a numeric run id"; fi
if [ -n "$TAG" ] && ! [[ "$TAG" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,100}$ ]]; then die "unsafe release tag: '$TAG'"; fi

for tool in gh jq sha256sum flock stat; do
    command -v "$tool" >/dev/null 2>&1 || die "missing tool: $tool"
done

# No prompts, no update nags: this must run unattended. Output of the auth check is discarded so a
# token can never reach the log.
export GH_PROMPT_DISABLED=1 GH_NO_UPDATE_NOTIFIER=1
gh auth status >/dev/null 2>&1 || die "gh is not authenticated: run 'gh auth login' (or export GH_TOKEN) and try again"

# ---- what is wanted -------------------------------------------------------------------------------
cd "$ROOT"
WANT_VERSION="${WANT_VERSION:-$(lanomat_version "$ROOT")}"
WANT_COMMIT="${WANT_COMMIT:-$(lanomat_commit "$ROOT")}"
lanomat_version_ok "$WANT_VERSION" || die "unsafe version string: '$WANT_VERSION' (allowed: letters, digits, . _ -)"
[[ "$WANT_COMMIT" =~ ^[A-Za-z0-9]{4,64}$ ]] || die "unusable commit id: '$WANT_COMMIT' (not a git checkout? pass --commit)"
case "$WANT_VERSION" in
    *-dirty) die "this checkout reports '$WANT_VERSION' (uncommitted changes): no CI build can ever match it; commit or discard the changes" ;;
esac
echo "==> Wanted client release: version $WANT_VERSION, commit $WANT_COMMIT"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
DL="$TMP/dl"
mkdir -p "$DL"

mismatch_help() {
    echo "fetch-client-release: $1" >&2
    echo "  Dispatch the workflow for the commit that is being deployed and run this script again:" >&2
    echo "    gh workflow run $WORKFLOW --ref <branch-or-tag-of-that-commit> -f version=$WANT_VERSION" >&2
    echo "  (see docs/infra/native-client-release.md), or build the Linux client locally with scripts/ops/build-client.sh." >&2
    exit 1
}

# ---- download ------------------------------------------------------------------------------------
manifest_of() { # manifest_of <dir> <jq filter>
    jq -r "$2" "$1/manifest.json" 2>/dev/null || true
}

if [ -n "$TAG" ]; then
    echo "==> Downloading the release $TAG"
    gh release download "$TAG" --dir "$DL" --clobber >/dev/null 2>&1 \
        || die "cannot download release '$TAG' (does it exist, draft releases need write access to the repository)"
elif [ -n "$RUN_ID" ]; then
    echo "==> Downloading artifact $ARTIFACT of run $RUN_ID"
    gh run download "$RUN_ID" --name "$ARTIFACT" --dir "$DL" >/dev/null 2>&1 \
        || die "cannot download artifact '$ARTIFACT' of run $RUN_ID (wrong id, run failed, or artifact expired: retention is 30 days)"
else
    echo "==> Looking for the newest successful $WORKFLOW run of commit $WANT_COMMIT"
    # headSha is the full sha: compare by prefix. Newest first.
    candidates="$(gh run list --workflow "$WORKFLOW" --status success --limit 50 \
        --json databaseId,headSha --jq ".[] | select(.headSha | startswith(\"$WANT_COMMIT\")) | .databaseId")" \
        || die "gh run list failed (repository not detected? run from the checkout, or set GH_REPO=owner/name)"
    [ -n "$candidates" ] || mismatch_help "no successful run of $WORKFLOW exists for commit $WANT_COMMIT."
    found=""
    while IFS= read -r id; do
        [ -n "$id" ] || continue
        rm -rf "${DL:?}"/* 2>/dev/null || true
        echo "    trying run $id"
        if gh run download "$id" --name "$ARTIFACT" --dir "$DL" >/dev/null 2>&1 \
            && [ -f "$DL/manifest.json" ] \
            && [ "$(manifest_of "$DL" '.app_version // empty')" = "$WANT_VERSION" ]; then
            found="$id"
            break
        fi
    done <<<"$candidates"
    [ -n "$found" ] || mismatch_help "successful runs exist for commit $WANT_COMMIT, but none built version '$WANT_VERSION' (or their artifact expired)."
    RUN_ID="$found"
    echo "==> Using run $RUN_ID"
fi

[ -f "$DL/manifest.json" ] || die "the download has no manifest.json"
[ -f "$DL/SHA256SUMS" ] || die "the download has no SHA256SUMS"
if [ -L "$DL/manifest.json" ] || [ -L "$DL/SHA256SUMS" ]; then die "manifest.json or SHA256SUMS is a link"; fi
[ "$(stat -c %s "$DL/manifest.json")" -le 262144 ] || die "manifest.json is implausibly large"
jq -e 'type == "object"' "$DL/manifest.json" >/dev/null 2>&1 || die "manifest.json is not a JSON object"

# ---- verify ------------------------------------------------------------------------------------------
GOT_VERSION="$(manifest_of "$DL" '.app_version // empty')"
GOT_COMMIT="$(manifest_of "$DL" '.commit // empty')"
API_VERSION="$(manifest_of "$DL" '.api_version // empty')"
BUILT_AT="$(manifest_of "$DL" '.built_at // empty')"
[ "$GOT_VERSION" = "$WANT_VERSION" ] || mismatch_help "the manifest is for version '$GOT_VERSION', this checkout needs '$WANT_VERSION'."
[ "$GOT_COMMIT" = "$WANT_COMMIT" ] || mismatch_help "the manifest was built from commit '$GOT_COMMIT', this checkout is '$WANT_COMMIT'."
if ! [[ "$API_VERSION" =~ ^[1-9][0-9]*$ ]]; then die "manifest api_version is not a positive integer"; fi
[ -n "$BUILT_AT" ] || die "manifest built_at is missing"
echo "==> Manifest matches: version $GOT_VERSION, commit $GOT_COMMIT, API v$API_VERSION"

# Structural validation of every entry with jq (the same rules as ClientBuilds::entry()), one
# error line per violation.
bad="$(jq -r --arg re "$NAME_RE" --arg v "$WANT_VERSION" '
    if (.files | type) != "array" or (.files | length) == 0 or (.files | length) > 64 then "files must be a list of 1..64 entries"
    else
      .files[] |
      if (type != "object") then "entry is not an object"
      elif ((.name | type) != "string") or (.name | test($re) | not) or (.name | contains("..")) or .name == "manifest.json" or .name == "SHA256SUMS" then "unsafe or invalid name: \(.name)"
      elif (.name | test("^lanomat-voice-(app|cli)-" + ($v | gsub("[.]"; "[.]")) + "-(linux|windows|macos)-(x86_64|aarch64)(-setup|-portable)?[.](tar[.]gz|zip|exe)$") | not) then "unexpected file name for version \($v): \(.name)"
      elif ((.os | IN("linux","windows","macos")) | not) or ((.arch | IN("x86_64","aarch64")) | not) or ((.kind | IN("app","cli")) | not) then "bad os/arch/kind for \(.name)"
      elif ((.size | type) != "number") or (.size != (.size | floor)) or .size < 0 then "bad size for \(.name)"
      elif ((.sha256 | type) != "string") or (.sha256 | test("^[a-f0-9]{64}$") | not) then "bad sha256 for \(.name)"
      elif (has("signed") and (.signed | type) != "boolean") then "bad signed flag for \(.name)"
      elif (has("variant") and (((.variant | type) != "string") or ((.variant | IN("installer","portable","archive")) | not))) then "bad variant for \(.name)"
      elif ((.variant // "archive") != (if (.name | test("-setup[.]exe$")) then "installer" elif (.name | test("-portable[.]exe$")) then "portable" else "archive" end)) then "variant does not match the file name: \(.name)"
      elif ((.variant // "archive") != "archive") and ((.os != "windows") or (.kind != "app")) then "only the Windows app comes as installer or portable: \(.name)"
      else empty end
    end' "$DL/manifest.json" 2>&1)" || die "manifest.json is malformed: $bad"
[ -z "$bad" ] || die "manifest.json is invalid: $bad"
[ "$(jq -r '[.files[].name] | (length == (unique | length))' "$DL/manifest.json")" = true ] || die "manifest.json lists a name twice"

# SHA256SUMS: only plain names allowed, so a crafted line cannot point outside the directory.
while IFS= read -r line; do
    [ -n "$line" ] || continue
    [[ "$line" =~ ^[a-f0-9]{64}\ [\ \*][A-Za-z0-9][A-Za-z0-9._-]*$ ]] || die "SHA256SUMS holds a line that is not '<sha256>  <plain name>': refusing"
done <"$DL/SHA256SUMS"

verified=0
unsigned_names=()
while IFS=$'\t' read -r name size sha signed; do
    path="$DL/$name"
    if [ ! -f "$path" ] || [ -L "$path" ]; then die "listed file is missing or a link: $name"; fi
    [ "$(stat -c %s "$path")" = "$size" ] || die "size mismatch for $name (manifest $size, file $(stat -c %s "$path"))"
    actual="$(sha256sum "$path" | cut -d' ' -f1)"
    [ "$actual" = "$sha" ] || die "sha256 mismatch for $name (manifest says $sha, the file is $actual): corrupted download or tampering"
    sums="$(awk -v n="$name" '{f=$2; sub(/^\*/,"",f)} f==n {print $1}' "$DL/SHA256SUMS")"
    [ "$sums" = "$sha" ] || die "SHA256SUMS disagrees with the manifest for $name"
    if [ "$signed" = false ]; then unsigned_names+=("$name"); fi
    verified=$((verified + 1))
done < <(jq -r '.files[] | [.name, .size, .sha256, (if has("signed") then .signed else "unknown" end | tostring)] | @tsv' "$DL/manifest.json")
echo "==> Verified $verified file(s): size and sha256 match the manifest and SHA256SUMS"

if [ "${#unsigned_names[@]}" -gt 0 ]; then
    if [ "$REQUIRE_SIGNED" = 1 ]; then
        die "unsigned files and --require-signed was given: ${unsigned_names[*]}"
    fi
    echo "    note: ${#unsigned_names[@]} file(s) are marked signed:false (Windows SmartScreen warns on first start; the download page explains it):"
    for n in "${unsigned_names[@]}"; do echo "      $n"; done
fi

# ---- lock, then merge with what the target already holds ------------------------------------------------
# The lock (shared with build-client.sh) is taken BEFORE the existing manifest is read, so a concurrent
# publisher cannot slip in between the merge and the switch. A dry run touches nothing.
if [ "$DRY" != 1 ]; then
    mkdir -p "$OUT"
    [ -w "$OUT" ] || die "$OUT is not writable (if Docker created it as root: sudo chown -R \"\$(id -u):\$(id -g)\" '$OUT')"
    OUT="$(cd "$OUT" && pwd)"
    exec 9>"$OUT/.lock"
    flock -n 9 || die "another build or fetch is publishing into $OUT"
fi

KEEP='[]'
if [ -f "$OUT/manifest.json" ] && [ ! -L "$OUT/manifest.json" ]; then
    old_version="$(jq -r '.app_version // empty' "$OUT/manifest.json" 2>/dev/null || true)"
    old_commit="$(jq -r '.commit // empty' "$OUT/manifest.json" 2>/dev/null || true)"
    if [ "$old_version" = "$WANT_VERSION" ] && [ "$old_commit" = "$WANT_COMMIT" ]; then
        # Candidates: the existing entries that are still intact on disk.
        intact='[]'
        while IFS=$'\t' read -r name size sha; do
            [[ "$name" =~ $NAME_RE ]] || continue
            p="$OUT/$name"
            if [ -f "$p" ] && [ ! -L "$p" ] && [ "$(stat -c %s "$p")" = "$size" ] \
                && [ "$(sha256sum "$p" | cut -d' ' -f1)" = "$sha" ]; then
                intact="$(jq -c --arg n "$name" '. + [$n]' <<<"$intact")"
            fi
        done < <(jq -r '(.files // [])[] | select((.name|type)=="string" and (.size|type)=="number" and (.sha256|type)=="string") | [.name, .size, .sha256] | @tsv' "$OUT/manifest.json" 2>/dev/null || true)
        KEEP="$(jq -c --argjson intact "$intact" --slurpfile new "$DL/manifest.json" --argjson prefer "$PREFER_WORKFLOW" '
            ($new[0].files) as $nf
            | [(.files // [])[]
               | select(.name as $n | $intact | index($n))
               | . as $o
               | select(
                   if $prefer == 1
                   then ($nf | map(select(.name == $o.name or (.os == $o.os and .arch == $o.arch and .kind == $o.kind and (.variant // "archive") == ($o.variant // "archive")))) | length) == 0
                   else true end)]' "$OUT/manifest.json")"
        echo "==> Existing manifest is for the same version and commit: keeping $(jq 'length' <<<"$KEEP") local entr(y/ies)"
    else
        echo "==> Existing manifest is for another version/commit ($old_version): its files will be removed"
    fi
fi

# New (workflow) entries that survive: when a local entry covers the same name or os/arch/kind and
# --prefer-workflow is off, the local one wins and the workflow's file is not published.
ADD="$(jq -c --argjson keep "$KEEP" '
    [ .files[] | . as $n
      | select(($keep | map(select(.name == $n.name or (.os == $n.os and .arch == $n.arch and .kind == $n.kind and (.variant // "archive") == ($n.variant // "archive")))) | length) == 0) ]' "$DL/manifest.json")"
skipped="$(jq -r --argjson add "$ADD" '[.files[].name] - ($add | map(.name)) | .[]' "$DL/manifest.json")"
if [ -n "$skipped" ]; then
    echo "    local entries win for (use --prefer-workflow to replace them):"
    while IFS= read -r n; do [ -z "$n" ] || echo "      $n"; done <<<"$skipped"
fi

MERGED="$TMP/manifest.json"
jq -n --arg v "$GOT_VERSION" --arg c "$GOT_COMMIT" --argjson api "$API_VERSION" --arg t "$BUILT_AT" \
    --argjson keep "$KEEP" --argjson add "$ADD" \
    '{app_version: $v, commit: $c, api_version: $api, built_at: $t, files: ($keep + $add)}' >"$MERGED"

if [ "$DRY" = 1 ]; then
    echo "==> --dry-run: would publish into $OUT (nothing was written). Merged manifest:"
    cat "$MERGED"
    exit 0
fi

# ---- publish: stage inside the target, files first, manifest last ----------------------------------------
STAGE="$OUT/.staging-$$"
trap 'rm -rf "$STAGE" "$TMP"' EXIT
rm -rf "$STAGE"
mkdir -p "$STAGE"

mapfile -t new_names < <(jq -r '.[].name' <<<"$ADD")
for n in "${new_names[@]}"; do
    [ -n "$n" ] || continue
    cp -- "$DL/$n" "$STAGE/$n"
    chmod 0644 "$STAGE/$n"
done
cp -- "$MERGED" "$STAGE/manifest.json"
chmod 0644 "$STAGE/manifest.json"

for n in "${new_names[@]}"; do
    [ -n "$n" ] || continue
    mv -f "$STAGE/$n" "$OUT/$n"
done
mv -f "$STAGE/manifest.json" "$OUT/manifest.json"

# Prune what the manifest no longer lists (older releases), after the switch.
for old in "$OUT"/lanomat-voice-*.tar.gz "$OUT"/lanomat-voice-*.zip "$OUT"/lanomat-voice-*.exe; do
    [ -e "$old" ] || continue
    name="$(basename "$old")"
    if ! jq -e --arg n "$name" '.files | map(.name) | index($n)' "$OUT/manifest.json" >/dev/null; then
        rm -f "$old"
        echo "    pruned $name"
    fi
done

echo "==> Published to $OUT"
jq -r '.files[] | "    \(.name)  \(.size) bytes  sha256 \(.sha256)  signed=\(if has("signed") then .signed else "unknown" end)"' "$OUT/manifest.json"
