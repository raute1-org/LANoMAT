#!/usr/bin/env bash
# Build the LANoMAT Launcher for Linux and publish it under clients-dist/launcher.
#
#   scripts/ops/build-launcher.sh                  # release build of the committed tree
#   scripts/ops/build-launcher.sh --allow-dirty    # also with uncommitted changes (testing only;
#                                                  # the version gets a -dirty suffix)
#   scripts/ops/build-launcher.sh --version X      # override the version string (needs --allow-dirty
#                                                  # unless it equals the checkout's own)
#   scripts/ops/build-launcher.sh --out DIR        # publish elsewhere (default: clients-dist/launcher)
#   scripts/ops/build-launcher.sh --rebuild-image  # rebuild the toolchain image even when
#                                                  # Dockerfile.dev is unchanged
#   scripts/ops/build-launcher.sh --keep-old       # keep older Linux tarballs in the output folder
#
# This script only COMPILES (`cargo build --locked --release`). The format/clippy/test gate is
# clients/launcher/ci/build.sh, the CI entry point; run that first when the Rust code changed.
# The launcher is a Tauri v2 app; its build runs in the reproducible
# clients/launcher/Dockerfile.dev image (there is no Rust toolchain on the host). The script writes
# manifest.json in the shape `App\Modules\GameRecipes\Support\LauncherBuilds` validates, the
# /launcher page renders and the Preflight `launcher_build` check reads. Only Linux x86_64 is built
# here; the Windows portable exe comes from .github/workflows/release-launcher.yml and is picked up
# by the manifest when it sits next to the tarball with the same version.
#
# The version in the file names and in the manifest is the one this script resolves (git describe,
# scripts/ops/lib/version.sh). The binary itself reports Cargo's package version (0.1.0) plus the
# commit it was built from: LANOMAT_BUILD_VERSION is not passed to it, because the Rust side ignores
# it. ci/build.sh does need it — it names that path's artifacts.
#
# The image is labelled with Dockerfile.dev's SHA-256 and rebuilt when that file changes; an image
# that predates the label is reused (with a notice), and --rebuild-image forces a rebuild. Publishing
# is staged inside <out>/.staging-<pid>: the tarball is moved into place first, manifest.json last, so
# a reader sees either the old or the new release. Older Linux tarballs in <out> are pruned unless
# --keep-old is given; a Windows exe of another version is left alone for its own release.
#
# The compile runs as `docker create`/`start`/`wait`/`logs` (not `docker run`), because this host's
# docker client has been seen to hang after the container had already finished. A hang therefore does
# not lose a finished build: when the staging binary exists and is executable it is packaged, with a
# warning. LANOMAT_LAUNCHER_BUILD_TIMEOUT (seconds, default 3600) bounds the wait.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=scripts/ops/lib/version.sh
source "$ROOT/scripts/ops/lib/version.sh"

ALLOW_DIRTY=0
REBUILD_IMAGE=0
KEEP_OLD=0
VERSION_OVERRIDE=""
OUT="$ROOT/clients-dist/launcher"
IMAGE="lanomat-launcher-dev"
OS="linux"
ARCH="x86_64"

die() { echo "build-launcher: $*" >&2; exit 1; }

usage() {
    # Everything between the shebang and `set -euo pipefail`, without the comment markers.
    awk 'NR > 1 && /^set -euo pipefail$/ { exit } NR > 1 { sub(/^# ?/, ""); print }' "${BASH_SOURCE[0]}"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --allow-dirty) ALLOW_DIRTY=1 ;;
        --rebuild-image) REBUILD_IMAGE=1 ;;
        --keep-old) KEEP_OLD=1 ;;
        --version) shift; VERSION_OVERRIDE="${1:?--version needs a value}" ;;
        --out) shift; OUT="${1:?--out needs a directory}" ;;
        -h|--help) usage; exit 0 ;;
        *) die "unknown argument: $1 (see --help)" ;;
    esac
    shift
done

for tool in docker jq tar gzip sha256sum stat flock; do
    command -v "$tool" >/dev/null 2>&1 || die "missing tool: $tool"
done
timeout 60 docker info >/dev/null 2>&1 || die "docker is not reachable"

# ---- version and tree state ---------------------------------------------------------------
cd "$ROOT"
TREE_VERSION="$(lanomat_version "$ROOT")"
COMMIT="$(lanomat_commit "$ROOT")"

# Dirty means: a tracked change anywhere, or untracked files that are not build output. Build
# output (clients-dist, launcher target/dist, .ci-cache, node_modules, vendor) says nothing about
# the source, so a checkout carrying only that stays clean.
BUILD_OUTPUT_RE='^(clients-dist|clients/launcher/target|clients/launcher/dist|\.ci-cache|node_modules|vendor)/'
TRACKED_DIRTY="$(git -C "$ROOT" status --porcelain --untracked-files=no 2>/dev/null || true)"
UNTRACKED_DIRTY="$(git -C "$ROOT" ls-files --others --exclude-standard --directory 2>/dev/null \
    | grep -Ev "$BUILD_OUTPUT_RE" || true)"

DIRTY=0
if [ -n "$TRACKED_DIRTY" ] || [ -n "$UNTRACKED_DIRTY" ]; then DIRTY=1; fi
if [ "$DIRTY" = 1 ] && [ "$ALLOW_DIRTY" != 1 ]; then
    echo "build-launcher: the working tree is not clean:" >&2
    [ -z "$TRACKED_DIRTY" ] || sed -n '1,5s/^/  changed:   /p' <<<"$TRACKED_DIRTY" >&2
    [ -z "$UNTRACKED_DIRTY" ] || sed -n '1,5s/^/  untracked: /p' <<<"$UNTRACKED_DIRTY" >&2
    echo "build-launcher: commit or ignore these files, or pass --allow-dirty" >&2
    exit 1
fi

VERSION="${VERSION_OVERRIDE:-$TREE_VERSION}"
if [ -n "$VERSION_OVERRIDE" ] && [ "$VERSION_OVERRIDE" != "$TREE_VERSION" ] && [ "$ALLOW_DIRTY" != 1 ]; then
    die "--version overrides the version this checkout reports (${TREE_VERSION}); pass --allow-dirty to confirm"
fi
lanomat_version_ok "$VERSION" || die "unsafe version string: '$VERSION' (allowed: letters, digits, . _ - ; at most 64)"

# `git describe` already appends -dirty for tracked changes; never double it, and never touch an
# explicit --version (it exists to reproduce a deployed server's string byte for byte). Untracked
# source files do not show up in `git describe`, so the suffix is added here for them as well.
if [ -z "$VERSION_OVERRIDE" ]; then
    case "$VERSION" in
        *-dirty) ;;
        *) if [ "$DIRTY" = 1 ]; then VERSION="$VERSION-dirty"; fi ;;
    esac
fi
# The suffix must not push the string past the manifest's 64-character limit.
lanomat_version_ok "$VERSION" || die "version with the dirty suffix is too long: '$VERSION'"

BUILT_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "==> LANoMAT Launcher $VERSION (commit $COMMIT) for $OS/$ARCH"

# ---- output directory ---------------------------------------------------------------------
mkdir -p "$OUT"
[ -w "$OUT" ] || die "$OUT is not writable (if Docker created it as root: sudo chown -R \"\$(id -u):\$(id -g)\" '$OUT')"
OUT="$(cd "$OUT" && pwd)"
exec 9>"$OUT/.lock"
flock -n 9 || die "another build is publishing into $OUT"

STAGE="$OUT/.staging-$$"
CONTAINER="lanomat-launcher-build-$$"
# Docker calls in the exit path get their own timeout: a hanging client must not hang the cleanup.
trap 'rm -rf "$STAGE"; timeout 15 docker rm -f "$CONTAINER" >/dev/null 2>&1 || true' EXIT
rm -rf "$STAGE"
mkdir -p "$STAGE/bin"

# ---- image and compile ----------------------------------------------------------------------
DOCKERFILE="clients/launcher/Dockerfile.dev"
IMAGE_SHA="$(sha256sum "$DOCKERFILE" | cut -d' ' -f1)"
image_sha() { timeout 30 docker image inspect -f '{{ index .Config.Labels "lanomat.dockerfile-sha" }}' "$IMAGE" 2>/dev/null || true; }
CURRENT_IMAGE_SHA="$(image_sha)"

# The image carries the hash of the Dockerfile it was built from: a dependency change rebuilds it
# instead of silently reusing a stale toolchain. An image that predates the label is reused with a
# notice (that is every image built before the label existed), and --rebuild-image forces the
# rebuild. Compare: missing image -> build; unlabelled image -> reuse; differing label -> build.
BUILD_IMAGE=0
if [ "$REBUILD_IMAGE" = 1 ]; then
    BUILD_IMAGE=1
    echo "==> Rebuilding $IMAGE because --rebuild-image was given"
elif ! timeout 30 docker image inspect "$IMAGE" >/dev/null 2>&1; then
    BUILD_IMAGE=1
    echo "==> Building image $IMAGE (target app: takes a while)"
elif [ -z "$CURRENT_IMAGE_SHA" ]; then
    echo "==> Reusing $IMAGE: it predates the dockerfile hash label (pass --rebuild-image to rebuild)"
elif [ "$CURRENT_IMAGE_SHA" != "$IMAGE_SHA" ]; then
    BUILD_IMAGE=1
    echo "==> Rebuilding $IMAGE: Dockerfile.dev changed (${CURRENT_IMAGE_SHA:0:12} -> ${IMAGE_SHA:0:12})"
fi
if [ "$BUILD_IMAGE" = 1 ]; then
    docker build --target app --label "lanomat.dockerfile-sha=$IMAGE_SHA" -t "$IMAGE" -f "$DOCKERFILE" clients/launcher \
        || die "cannot build the toolchain image"
fi

# The cargo registry and target live in named volumes: nothing root-owned lands in the checkout,
# and the second build is incremental. The binary and the hicolor icon are handed back to the
# invoking user. The icon is scaled to 256x256 because that is the hicolor size
# clients/launcher/scripts/install-desktop.sh installs into; doing it in the pinned image keeps
# both packagers byte-identical.
#
# create/start/wait instead of `docker run`: the client has hung after a finished container, and a
# hang must never discard a build that is already on disk. The staging folder was emptied above, so
# anything in it comes from this run.
echo "==> Compiling the release binary in $IMAGE"
COMPILE_TIMEOUT="${LANOMAT_LAUNCHER_BUILD_TIMEOUT:-3600}"
timeout 30 docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
# The single-quoted container script expands its variables inside the container (HOST_UID etc.),
# never in this shell.
# shellcheck disable=SC2016
timeout 120 docker create --name "$CONTAINER" \
    -e LANOMAT_BUILD_COMMIT="$COMMIT" \
    -e LANOMAT_LAUNCHER_COMMIT="$COMMIT" \
    -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" \
    -v "$ROOT/clients/launcher:/work" \
    -v lanomat-cargo-registry:/usr/local/cargo/registry \
    -v lanomat-launcher-target:/work/target \
    -v "$STAGE/bin:/out" \
    -w /work "$IMAGE" bash -c '
        set -euo pipefail
        command -v convert >/dev/null || { echo "imagemagick (convert) is missing in the image" >&2; exit 1; }
        cargo build --locked --release -p lanomat-launcher-app
        install -m 0755 target/release/lanomat-launcher /out/
        convert app/icons/icon.png -resize 256x256 -strip /out/lanomat-launcher.png
        chown "$HOST_UID:$HOST_GID" /out/lanomat-launcher /out/lanomat-launcher.png
    ' || die "cannot create the build container"

timeout 60 docker start "$CONTAINER" >/dev/null || die "cannot start the build container"

WAIT_RC=0
timeout "$COMPILE_TIMEOUT" docker wait "$CONTAINER" > "$STAGE/.exit-code" 2>/dev/null || WAIT_RC=$?
# Never block on the log stream either; the container is stopped by now (or killed below).
timeout 120 docker logs "$CONTAINER" 2>&1 || true

EXIT_CODE=""
if [ "$WAIT_RC" = 124 ]; then
    echo "build-launcher: docker wait did not return within ${COMPILE_TIMEOUT}s (docker client hang)" >&2
    timeout 30 docker kill "$CONTAINER" >/dev/null 2>&1 || true
elif [ "$WAIT_RC" != 0 ]; then
    echo "build-launcher: docker wait failed (exit ${WAIT_RC})" >&2
else
    EXIT_CODE="$(cat "$STAGE/.exit-code" 2>/dev/null || true)"
fi
rm -f "$STAGE/.exit-code"
timeout 30 docker rm -f "$CONTAINER" >/dev/null 2>&1 || true

BINARY="$STAGE/bin/lanomat-launcher"
if [ "$EXIT_CODE" != 0 ]; then
    # A hang (or a non-zero container exit) is only fatal when nothing usable landed on disk.
    if [ -x "$BINARY" ] && [ -s "$BINARY" ]; then
        echo "build-launcher: the container reported '${EXIT_CODE:-no exit code}', but $BINARY is present; packaging it" >&2
    else
        die "the compile step produced no executable at $BINARY${EXIT_CODE:+ (container exit $EXIT_CODE)}"
    fi
fi
[ -x "$BINARY" ] || die "the compile step produced no executable at $BINARY"
[ -s "$BINARY" ] || die "the compiled binary is empty"
[ -s "$STAGE/bin/lanomat-launcher.png" ] || die "the compile step produced no icon"

# ---- package ----------------------------------------------------------------------------------
PKG="lanomat-launcher-$VERSION-$OS-$ARCH"
ARCHIVE="$PKG.tar.gz"
PKG_DIR="$STAGE/$PKG"
# Explicit mode: the tarball records this directory, so the archive must not depend on the umask.
# (No -p: the stage directory above exists, so this is the only directory created here.)
mkdir -m 0755 "$PKG_DIR"
install -m 0755 "$BINARY" "$PKG_DIR/lanomat-launcher"
install -m 0644 "$STAGE/bin/lanomat-launcher.png" "$PKG_DIR/lanomat-launcher.png"

# The desktop entry and the readme come from clients/launcher/scripts: the installer renders
# the entry (`--print-desktop`) and rewrites Exec= to the absolute binary path, and
# liesmich.txt is the one readme both build paths fill in. One source, so the two packagers
# cannot drift apart (R4). install-terminal.sh sits next to install-desktop.sh because it calls
# it as its sibling (arcade/gaming-terminal spin-up: autostart, kiosk, cabinet name).
sh "$ROOT/clients/launcher/scripts/install-desktop.sh" --print-desktop \
    > "$PKG_DIR/lanomat-launcher.desktop" || die "cannot render the desktop entry"
chmod 0644 "$PKG_DIR/lanomat-launcher.desktop"
install -m 0755 "$ROOT/clients/launcher/scripts/install-desktop.sh" "$PKG_DIR/install-desktop.sh"
install -m 0755 "$ROOT/clients/launcher/scripts/install-terminal.sh" "$PKG_DIR/install-terminal.sh"

sed -e "s|@VERSION@|$VERSION|g" \
    -e "s|@COMMIT@|$COMMIT|g" \
    -e "s|@PKG@|$PKG|g" \
    -e "s|@ARCHIVE@|$ARCHIVE|g" \
    "$ROOT/clients/launcher/scripts/liesmich.txt" > "$PKG_DIR/LIESMICH.txt"
chmod 0644 "$PKG_DIR/LIESMICH.txt"

# Reproducible: sorted entries, fixed mtime, root ownership, no gzip timestamp.
tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner \
    -C "$STAGE" -cf - "$PKG" | gzip -9 -n > "$STAGE/$ARCHIVE"
rm -rf "${PKG_DIR:?}"
mv "$STAGE/$ARCHIVE" "$OUT/$ARCHIVE"
chmod 0644 "$OUT/$ARCHIVE"

# ---- manifest and checksums -------------------------------------------------------------------
entry() { # entry <name> <os> <arch> <kind> <variant> <signed>
    local file="$OUT/$1"
    jq -c -n --arg name "$1" --arg os "$2" --arg arch "$3" --arg kind "$4" --arg variant "$5" \
        --argjson size "$(stat -c %s "$file")" \
        --arg sha "$(sha256sum "$file" | cut -d' ' -f1)" \
        --argjson signed "$6" \
        '{name: $name, os: $os, arch: $arch, kind: $kind, variant: $variant,
          size: $size, sha256: $sha, signed: $signed}'
}

ENTRIES="$STAGE/files.jsonl"
: > "$ENTRIES"
entry "$ARCHIVE" "$OS" "$ARCH" app archive false >> "$ENTRIES"

# A Windows portable exe / setup next to the tarball with the SAME version belongs in the
# release; another version's file is left for its own manifest.
add_windows() { # add_windows <variant> <suffix>
    local name="lanomat-launcher-$VERSION-windows-x86_64-$2.exe"
    if [ -f "$OUT/$name" ]; then
        entry "$name" windows x86_64 app "$1" false >> "$ENTRIES"
    fi
}
add_windows portable portable
add_windows installer setup

jq -n --arg version "$VERSION" --arg commit "$COMMIT" --arg built_at "$BUILT_AT" \
    --slurpfile files "$ENTRIES" \
    '{app_version: $version, commit: $commit, built_at: $built_at, files: $files}' \
    > "$STAGE/manifest.json"

# Exactly the files the manifest lists, so SHA256SUMS and /launcher never disagree.
( cd "$OUT" && jq -r '.name' "$ENTRIES" | while IFS= read -r name; do sha256sum -- "$name"; done \
    > "$STAGE/SHA256SUMS" )

mv "$STAGE/SHA256SUMS" "$OUT/SHA256SUMS"
# manifest.json LAST: until it is renamed, readers keep seeing the previous release.
mv "$STAGE/manifest.json" "$OUT/manifest.json"
chmod 0644 "$OUT/SHA256SUMS" "$OUT/manifest.json"

# ---- prune old Linux tarballs ----------------------------------------------------------------
# Build output, not history: without this the bind-mounted folder grows by one tarball per release.
# Only this script's own artifact is pruned; a Windows exe of another version keeps its release
# (the manifest ignores it) and SHA256SUMS/manifest.json are overwritten anyway.
if [ "$KEEP_OLD" != 1 ]; then
    for old in "$OUT"/lanomat-launcher-*-linux-x86_64.tar.gz; do
        [ -e "$old" ] || continue
        if [ "$old" != "$OUT/$ARCHIVE" ]; then
            rm -f -- "$old" && echo "pruned $old"
        fi
    done
fi

SIZE="$(stat -c %s "$OUT/$ARCHIVE")"
SHA="$(sha256sum "$OUT/$ARCHIVE" | cut -d' ' -f1)"
echo "published $OUT/$ARCHIVE ($SIZE bytes)"
echo "sha256   $SHA"
echo "manifest $OUT/manifest.json"
