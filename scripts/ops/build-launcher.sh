#!/usr/bin/env bash
# Build the LANoMAT Launcher for Linux (or, on macOS, its .app/.dmg) and publish it under
# clients-dist/launcher.
#
#   scripts/ops/build-launcher.sh                  # release build of the committed tree
#   scripts/ops/build-launcher.sh --allow-dirty    # also with uncommitted changes (testing only;
#                                                  # the version gets a -dirty suffix)
#   scripts/ops/build-launcher.sh --version X      # override the version string (needs --allow-dirty
#                                                  # unless it equals the checkout's own)
#   scripts/ops/build-launcher.sh --out DIR        # publish elsewhere (default: clients-dist/launcher)
#   scripts/ops/build-launcher.sh --rebuild-image  # rebuild the toolchain image even when
#                                                  # Dockerfile.dev is unchanged
#   scripts/ops/build-launcher.sh --keep-old       # keep older artifacts in the output folder
#
# The target follows the host: Linux builds the x86_64 tarball in Docker, macOS builds the native
# .app and .dmg with `cargo tauri build --bundles app,dmg` (run it on macOS; there is no cross-build).
# Set LANOMAT_LAUNCHER_MACOS_TARGET=aarch64-apple-darwin|x86_64-apple-darwin to pick the other
# macOS architecture on a universal host.
#
# This script only COMPILES (`cargo build --locked --release`). The format/clippy/test gate is
# clients/launcher/ci/build.sh, the CI entry point; run that first when the Rust code changed.
# The launcher is a Tauri v2 app. On Linux its build runs in the reproducible
# clients/launcher/Dockerfile.dev image (there is no Rust toolchain on the host). On macOS it builds
# natively with `cargo tauri build --bundles app,dmg`, because the .app/.dmg need Apple's hdiutil and
# codesign and cannot be cross-built in the Linux image. The script writes manifest.json in the shape
# `App\Modules\GameRecipes\Support\LauncherBuilds` validates, the /launcher page renders and the
# Preflight `launcher_build` check reads. Linux x86_64 and macOS (arm64 or x86_64) are built here; the
# Windows portable exe comes from .github/workflows/release-launcher.yml and is picked up by the
# manifest when it sits next to the other files with the same version.
#
# macOS artifacts use `variant: archive` (LauncherBuilds only accepts installer|portable|archive and
# reserves the other two for Windows) plus a `format` field: `dmg` for the disk image, `app` for the
# zipped .app. The release workflow builds both architectures; this helper builds the host's arch
# unless LANOMAT_LAUNCHER_MACOS_TARGET names aarch64-apple-darwin or x86_64-apple-darwin.
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

die() { echo "build-launcher: $*" >&2; exit 1; }

# BSD (macOS) and GNU userland disagree about stat/sha256sum; both are only thin wrappers so the
# packaging code below reads the same on either host.
file_size() { stat -c %s "$1" 2>/dev/null || stat -f %z "$1"; }
sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum -- "$1" | cut -d' ' -f1
    else
        shasum -a 256 "$1" | cut -d' ' -f1
    fi
}

# One manifest entry in the shape LauncherBuilds validates. `format` is an extra, server-ignored
# label that names the payload (tar.gz, exe, dmg, app) where `variant` alone is ambiguous.
entry() { # entry <name> <os> <arch> <kind> <variant> <signed> [format]
    local file="$OUT/$1"
    local format="${7:-}"
    jq -c -n --arg name "$1" --arg os "$2" --arg arch "$3" --arg kind "$4" --arg variant "$5" \
        --arg format "$format" \
        --argjson size "$(file_size "$file")" \
        --arg sha "$(sha256_of "$file")" \
        --argjson signed "$6" \
        '{name: $name, os: $os, arch: $arch, kind: $kind, variant: $variant,
          size: $size, sha256: $sha, signed: $signed}
         + (if $format == "" then {} else {format: $format} end)'
}

# A Windows portable exe / setup next to the other files with the SAME version belongs in the
# release; another version's file is left for its own manifest.
add_windows() { # add_windows <variant> <suffix> [format]
    local name="lanomat-launcher-$VERSION-windows-x86_64-$2.exe"
    if [ -f "$OUT/$name" ]; then
        entry "$name" windows x86_64 app "$1" false "${3:-exe}" >> "$ENTRIES"
    fi
}

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

# ---- host and target -------------------------------------------------------------------------
# The target follows the host: a macOS .app/.dmg needs hdiutil/codesign, so it cannot be produced
# in the Linux image. Windows is not built here at all (see the release workflow).
case "$(uname -s)" in
    Darwin)
        HOST_OS=macos
        case "$(uname -m)" in
            arm64) HOST_ARCH=aarch64 ;;
            x86_64) HOST_ARCH=x86_64 ;;
            *) die "unsupported macOS architecture: $(uname -m) (arm64 or x86_64)" ;;
        esac
        ;;
    Linux)
        HOST_OS=linux
        HOST_ARCH=x86_64
        ;;
    *)
        die "unsupported host: $(uname -s) (build on Linux or macOS; Windows comes from the release workflow)"
        ;;
esac

OS="${LANOMAT_LAUNCHER_OS:-$HOST_OS}"
ARCH="${LANOMAT_LAUNCHER_ARCH:-$HOST_ARCH}"
case "$OS" in linux|macos) ;; *) die "unsupported target OS: '$OS' (linux or macos)" ;; esac
case "$ARCH" in x86_64|aarch64) ;; *) die "unsupported target arch: '$ARCH' (x86_64 or aarch64)" ;; esac

# A macOS target triple names the arch the manifest has to carry; derive it instead of letting an
# x86_64 build be published under an arm64 file name.
if [ "$OS" = macos ] && [ -n "${LANOMAT_LAUNCHER_MACOS_TARGET:-}" ]; then
    case "$LANOMAT_LAUNCHER_MACOS_TARGET" in
        aarch64-apple-darwin) TARGET_ARCH=aarch64 ;;
        x86_64-apple-darwin) TARGET_ARCH=x86_64 ;;
        universal-apple-darwin) TARGET_ARCH="" ;;
        *) die "unsupported LANOMAT_LAUNCHER_MACOS_TARGET: '$LANOMAT_LAUNCHER_MACOS_TARGET'" ;;
    esac
    if [ -n "$TARGET_ARCH" ]; then
        if [ -n "${LANOMAT_LAUNCHER_ARCH:-}" ] && [ "$LANOMAT_LAUNCHER_ARCH" != "$TARGET_ARCH" ]; then
            die "LANOMAT_LAUNCHER_ARCH=$LANOMAT_LAUNCHER_ARCH contradicts LANOMAT_LAUNCHER_MACOS_TARGET=$LANOMAT_LAUNCHER_MACOS_TARGET"
        fi
        ARCH="$TARGET_ARCH"
    fi
fi
if [ "$OS" != "$HOST_OS" ]; then
    if [ "$OS" = macos ]; then
        die "macOS must be built on a macOS host (this is a $HOST_OS host; .app/.dmg need hdiutil/codesign)"
    fi
    die "$OS must be built on a $OS host (this is a $HOST_OS host)"
fi

if [ "$OS" = macos ]; then
    for tool in cargo jq ditto shasum stat git; do
        command -v "$tool" >/dev/null 2>&1 || die "missing tool: $tool"
    done
else
    for tool in docker jq tar gzip sha256sum stat flock; do
        command -v "$tool" >/dev/null 2>&1 || die "missing tool: $tool"
    done
    timeout 60 docker info >/dev/null 2>&1 || die "docker is not reachable"
fi

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
if [ "$OS" = macos ]; then
    # macOS has no flock(1); a directory is the portable lock.
    LOCK_DIR="$OUT/.lock.d"
    mkdir "$LOCK_DIR" 2>/dev/null || die "another build is publishing into $OUT"
else
    exec 9>"$OUT/.lock"
    flock -n 9 || die "another build is publishing into $OUT"
fi

STAGE="$OUT/.staging-$$"
CONTAINER="lanomat-launcher-build-$$"
cleanup() {
    rm -rf "$STAGE"
    if [ -n "${LOCK_DIR:-}" ]; then rmdir "$LOCK_DIR" 2>/dev/null || true; fi
    # Docker calls in the exit path get their own timeout: a hanging client must not hang cleanup.
    if [ "$OS" != macos ] && command -v docker >/dev/null 2>&1; then
        if command -v timeout >/dev/null 2>&1; then
            timeout 15 docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
        else
            docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
        fi
    fi
}
trap cleanup EXIT
rm -rf "$STAGE"
mkdir -p "$STAGE/bin"

# ---- macOS: native .app/.dmg via the Tauri bundler ------------------------------------------
if [ "$OS" = macos ]; then
    cargo tauri --version >/dev/null 2>&1 \
        || die "the Tauri CLI is missing: cargo install tauri-cli --version '^2' --locked"
    TARGET="${LANOMAT_LAUNCHER_MACOS_TARGET:-$ARCH-apple-darwin}"
    case "$TARGET" in
        aarch64-apple-darwin|x86_64-apple-darwin) ;;
        universal-apple-darwin)
            die "universal-apple-darwin has no single arch for the manifest; build aarch64-apple-darwin and x86_64-apple-darwin separately (a local universal app is shown in clients/launcher/README.md)"
            ;;
        *) die "unsupported macOS target: '$TARGET' (aarch64-apple-darwin or x86_64-apple-darwin)" ;;
    esac

    echo "==> Compiling the macOS bundle in clients/launcher/app ($TARGET)"
    ( cd "$ROOT/clients/launcher/app" \
        && LANOMAT_LAUNCHER_COMMIT="$COMMIT" cargo tauri build --target "$TARGET" --bundles app,dmg --ci )

    PRODUCT="$(jq -r '.productName' "$ROOT/clients/launcher/app/tauri.conf.json")"
    BUNDLE_DIR="$ROOT/clients/launcher/target/$TARGET/release/bundle"
    APP_BUNDLE="$BUNDLE_DIR/macos/$PRODUCT.app"
    DMG_SRC="$(ls -1 "$BUNDLE_DIR"/dmg/*.dmg 2>/dev/null | head -n 1 || true)"
    [ -d "$APP_BUNDLE" ] || die "the build produced no app bundle at $APP_BUNDLE"
    [ -n "$DMG_SRC" ] || die "the build produced no .dmg under $BUNDLE_DIR/dmg"
    MAIN_BIN="$APP_BUNDLE/Contents/MacOS/lanomat-launcher"
    [ -x "$MAIN_BIN" ] || die "the app bundle has no executable at $MAIN_BIN"

    # The manifest must not claim a signature the bundle does not carry.
    SIGNED=false
    if codesign --verify --deep --strict "$APP_BUNDLE" >/dev/null 2>&1; then SIGNED=true; fi

    DMG_NAME="lanomat-launcher-$VERSION-macos-$ARCH.dmg"
    APP_NAME="lanomat-launcher-$VERSION-macos-$ARCH.zip"
    install -m 0644 "$DMG_SRC" "$STAGE/$DMG_NAME"
    # A zipped .app is the fallback for download tools that refuse disk images; ditto preserves
    # the bundle's resource forks and permissions.
    ditto -c -k --sequesterRsrc --keepParent "$APP_BUNDLE" "$STAGE/$APP_NAME"
    # Files first, manifest last: a reader sees either the old or the new release.
    mv "$STAGE/$DMG_NAME" "$OUT/$DMG_NAME"
    mv "$STAGE/$APP_NAME" "$OUT/$APP_NAME"
    chmod 0644 "$OUT/$DMG_NAME" "$OUT/$APP_NAME"

    # ---- manifest and checksums --------------------------------------------------------------
    ENTRIES="$STAGE/files.jsonl"
    : > "$ENTRIES"
    entry "$DMG_NAME" macos "$ARCH" app archive "$SIGNED" dmg >> "$ENTRIES"
    entry "$APP_NAME" macos "$ARCH" app archive "$SIGNED" app >> "$ENTRIES"
    add_windows portable portable exe
    add_windows installer setup exe

    jq -n --arg version "$VERSION" --arg commit "$COMMIT" --arg built_at "$BUILT_AT" \
        --slurpfile files "$ENTRIES" \
        '{app_version: $version, commit: $commit, built_at: $built_at, files: $files}' \
        > "$STAGE/manifest.json"

    ( cd "$OUT" && jq -r '.name' "$ENTRIES" | while IFS= read -r name; do
        printf '%s  %s\n' "$(sha256_of "$name")" "$name"
    done > "$STAGE/SHA256SUMS" )
    mv "$STAGE/SHA256SUMS" "$OUT/SHA256SUMS"
    mv "$STAGE/manifest.json" "$OUT/manifest.json"
    chmod 0644 "$OUT/SHA256SUMS" "$OUT/manifest.json"

    # Older macOS files of another version are build output, not history.
    if [ "$KEEP_OLD" != 1 ]; then
        for old in "$OUT"/lanomat-launcher-*-macos-*.dmg "$OUT"/lanomat-launcher-*-macos-*.zip; do
            [ -e "$old" ] || continue
            case "$(basename "$old")" in
                "$DMG_NAME"|"$APP_NAME") ;;
                *) rm -f -- "$old" && echo "pruned $old" ;;
            esac
        done
    fi

    echo "published $OUT/$DMG_NAME ($(file_size "$OUT/$DMG_NAME") bytes)"
    echo "published $OUT/$APP_NAME ($(file_size "$OUT/$APP_NAME") bytes)"
    echo "manifest  $OUT/manifest.json"
    exit 0
fi

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
ENTRIES="$STAGE/files.jsonl"
: > "$ENTRIES"
entry "$ARCHIVE" "$OS" "$ARCH" app archive false tar.gz >> "$ENTRIES"
add_windows portable portable exe
add_windows installer setup exe

jq -n --arg version "$VERSION" --arg commit "$COMMIT" --arg built_at "$BUILT_AT" \
    --slurpfile files "$ENTRIES" \
    '{app_version: $version, commit: $commit, built_at: $built_at, files: $files}' \
    > "$STAGE/manifest.json"

# Exactly the files the manifest lists, so SHA256SUMS and /launcher never disagree.
( cd "$OUT" && jq -r '.name' "$ENTRIES" | while IFS= read -r name; do
    printf '%s  %s\n' "$(sha256_of "$name")" "$name"
done > "$STAGE/SHA256SUMS" )

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

SIZE="$(file_size "$OUT/$ARCHIVE")"
SHA="$(sha256_of "$OUT/$ARCHIVE")"
echo "published $OUT/$ARCHIVE ($SIZE bytes)"
echo "sha256   $SHA"
echo "manifest $OUT/manifest.json"
