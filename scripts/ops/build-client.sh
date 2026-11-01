#!/usr/bin/env bash
# Build the native voice client for the version of THIS checkout and publish it to clients-dist/,
# where the app serves it on /talk/client (compose mounts ./clients-dist read-only).
#
#   scripts/ops/build-client.sh                  # release build of the committed tree
#   scripts/ops/build-client.sh --allow-dirty    # also with uncommitted changes (testing only)
#   scripts/ops/build-client.sh --version X      # override the version string (needs --allow-dirty
#                                                # unless it equals the checkout's own)
#   scripts/ops/build-client.sh --out DIR        # publish elsewhere (default: clients-dist)
#
# What it does: builds `lanomat-voice` (CLI) and `lanomat-voice-app` (Tauri) for linux x86_64 in the
# docker images of clients/voice (no Rust toolchain on the host; cargo registry and target live in
# the named volumes lanomat-cargo-registry / lanomat-cargo-target), packs each into a .tar.gz and
# writes manifest.json next to them. Only Linux x86_64 is built: there is no Windows or macOS
# builder, and the manifest says so by simply not listing them.
#
# Atomicity: everything is staged inside <out>/.staging-<pid> (same filesystem), tarballs are moved
# into place first and manifest.json is renamed LAST, so a reader sees either the old or the new
# release, never a mix. The directory itself is never replaced: a bind-mounted directory would keep
# pointing at the old inode. Re-running is safe; files of older releases are pruned afterwards.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=lib/version.sh
source "$ROOT/scripts/ops/lib/version.sh"

ALLOW_DIRTY=0
VERSION_OVERRIDE=""
OUT="$ROOT/clients-dist"
CORE_IMAGE="lanomat-voice-dev"
APP_IMAGE="lanomat-voice-app-dev"
OS="linux"
ARCH="x86_64"

die() { echo "build-client: $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --allow-dirty) ALLOW_DIRTY=1 ;;
        --version) shift; VERSION_OVERRIDE="${1:?--version needs a value}" ;;
        --out) shift; OUT="${1:?--out needs a directory}" ;;
        -h|--help) sed -n '2,19p' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) die "unknown argument: $1 (see --help)" ;;
    esac
    shift
done

for tool in docker jq tar gzip sha256sum flock; do
    command -v "$tool" >/dev/null 2>&1 || die "missing tool: $tool"
done
[ "$(uname -m)" = "x86_64" ] || echo "build-client: note, the host is not x86_64; the images build for the docker platform" >&2

# ---- version and tree state ---------------------------------------------------------------
cd "$ROOT"
TREE_VERSION="$(lanomat_version "$ROOT")"
COMMIT="$(lanomat_commit "$ROOT")"
DIRTY=0
if [ -n "$(git -C "$ROOT" status --porcelain --untracked-files=no 2>/dev/null)" ]; then DIRTY=1; fi
if [ "$DIRTY" = 1 ] && [ "$ALLOW_DIRTY" != 1 ]; then
    die "the working tree has uncommitted changes; commit them or pass --allow-dirty (a client built from a dirty tree can never match a deployed server)"
fi
VERSION="${VERSION_OVERRIDE:-$TREE_VERSION}"
if [ -n "$VERSION_OVERRIDE" ] && [ "$VERSION_OVERRIDE" != "$TREE_VERSION" ] && [ "$ALLOW_DIRTY" != 1 ]; then
    die "--version overrides the version this checkout reports (${TREE_VERSION}); pass --allow-dirty to confirm"
fi
lanomat_version_ok "$VERSION" || die "unsafe version string: '$VERSION' (allowed: letters, digits, . _ -)"

API_VERSION="$(grep -oE "API_VERSION = [0-9]+" app/Modules/WebVoice/NativeClient/Support/NativeClientConfig.php | grep -oE "[0-9]+$" || true)"
[[ "$API_VERSION" =~ ^[0-9]+$ ]] || die "cannot read API_VERSION from NativeClientConfig.php"

echo "==> Client build for version $VERSION (commit $COMMIT, API v$API_VERSION, $OS/$ARCH)"

# ---- images ---------------------------------------------------------------------------------
if ! docker image inspect "$CORE_IMAGE" >/dev/null 2>&1; then
    echo "==> Building image $CORE_IMAGE (first run: takes a while)"
    docker build --target core -t "$CORE_IMAGE" -f clients/voice/Dockerfile.dev clients/voice
fi
if ! docker image inspect "$APP_IMAGE" >/dev/null 2>&1; then
    echo "==> Building image $APP_IMAGE (first run: takes a while)"
    docker build --target app -t "$APP_IMAGE" -f clients/voice/Dockerfile.dev clients/voice
fi

# ---- output directory ---------------------------------------------------------------------
mkdir -p "$OUT"
[ -w "$OUT" ] || die "$OUT is not writable (if Docker created it as root: sudo chown -R \"\$(id -u):\$(id -g)\" '$OUT')"
OUT="$(cd "$OUT" && pwd)"
exec 9>"$OUT/.lock"
flock -n 9 || die "another build is publishing into $OUT"

STAGE="$OUT/.staging-$$"
trap 'rm -rf "$STAGE"' EXIT
rm -rf "$STAGE"
mkdir -p "$STAGE/bin"

# ---- compile ----------------------------------------------------------------------------------
# One cargo run builds both binaries (they share the target dir). The version reaches the code
# through LANOMAT_BUILD_VERSION / LANOMAT_BUILD_COMMIT (voice_core::build_info). The staged
# binaries are handed back to the invoking user.
echo "==> Compiling release binaries in $APP_IMAGE (incremental; the first build is slow)"
docker run --rm --network host \
    -e LANOMAT_BUILD_VERSION="$VERSION" -e LANOMAT_BUILD_COMMIT="$COMMIT" \
    -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" \
    -v "$ROOT/clients/voice:/work" \
    -v lanomat-cargo-registry:/usr/local/cargo/registry \
    -v lanomat-cargo-target:/work/target \
    -v "$STAGE/bin:/out" \
    -w /work "$APP_IMAGE" bash -c '
        set -euo pipefail
        cargo build --release -p voice-cli -p lanomat-voice-app
        install -m 0755 target/release/lanomat-voice target/release/lanomat-voice-app /out/
        chown "$HOST_UID:$HOST_GID" /out/lanomat-voice /out/lanomat-voice-app
    '
[ -x "$STAGE/bin/lanomat-voice" ] && [ -x "$STAGE/bin/lanomat-voice-app" ] || die "the compile step produced no binaries"

# ---- package ----------------------------------------------------------------------------------
pack() { # pack <kind> <package-dir-name> <tarball-name> : the directory is already filled in $STAGE
    local dir="$1" tarball="$2"
    # Reproducible: sorted entries, fixed mtime, root ownership, no gzip timestamp.
    tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner \
        -C "$STAGE" -cf - "$dir" | gzip -9 -n > "$STAGE/$tarball"
    rm -rf "${STAGE:?}/$dir"
}

APP_DIR="lanomat-voice-app-$VERSION-$OS-$ARCH"
CLI_DIR="lanomat-voice-cli-$VERSION-$OS-$ARCH"
APP_TAR="$APP_DIR.tar.gz"
CLI_TAR="$CLI_DIR.tar.gz"

mkdir -p "$STAGE/$APP_DIR" "$STAGE/$CLI_DIR"
install -m 0755 "$STAGE/bin/lanomat-voice-app" "$STAGE/$APP_DIR/lanomat-voice-app"
install -m 0644 "$ROOT/clients/voice/app/icons/icon.png" "$STAGE/$APP_DIR/lanomat-voice.png"
install -m 0755 "$STAGE/bin/lanomat-voice" "$STAGE/$CLI_DIR/lanomat-voice"

cat > "$STAGE/$APP_DIR/lanomat-voice-app.desktop" <<'DESKTOP'
[Desktop Entry]
Type=Application
Name=LANoMAT Voice
Comment=Sprachchat und LAN-Party-Überblick für LANoMAT
Exec=lanomat-voice-app
Icon=lanomat-voice
Terminal=false
Categories=AudioVideo;Audio;Network;
StartupWMClass=lanomat-voice-app
DESKTOP
chmod 0644 "$STAGE/$APP_DIR/lanomat-voice-app.desktop"

cat > "$STAGE/$APP_DIR/LIESMICH.txt" <<README
LANoMAT Voice (Desktop-App), Version $VERSION, Linux x86_64
Gebaut aus Commit $COMMIT, passend zum LANoMAT-Server derselben Version.

Installieren:
  1. Entpacken:            tar -xzf $APP_TAR
  2. Ausführbar machen:    chmod +x lanomat-voice-app
  3. Starten:              ./lanomat-voice-app

Als Programm im Startmenü (optional):
  mkdir -p ~/.local/bin ~/.local/share/applications ~/.local/share/icons/hicolor/512x512/apps
  cp lanomat-voice-app ~/.local/bin/
  cp lanomat-voice.png ~/.local/share/icons/hicolor/512x512/apps/
  cp lanomat-voice-app.desktop ~/.local/share/applications/
  (~/.local/bin muss im PATH liegen.)

Beim ersten Start verbindest du die App mit deinem LANoMAT-Konto: Adresse des Servers
eingeben, den angezeigten Code im Browser unter "Verbundene Geräte" bestätigen.

Voraussetzungen: eine aktuelle Linux-Distribution mit GTK 3, WebKitGTK 4.1 und ALSA
oder PulseAudio/PipeWire. Prüfsumme (SHA-256) der Datei steht auf der Download-Seite.
README
cat > "$STAGE/$CLI_DIR/LIESMICH.txt" <<README
lanomat-voice (Kommandozeile), Version $VERSION, Linux x86_64
Gebaut aus Commit $COMMIT, passend zum LANoMAT-Server derselben Version.

Installieren:
  1. Entpacken:            tar -xzf $CLI_TAR
  2. Ausführbar machen:    chmod +x lanomat-voice
  3. Starten:              ./lanomat-voice --version
                           ./lanomat-voice login https://<ADRESSE-DES-SERVERS>

Hilfe: ./lanomat-voice --help. Für die grafische App lade stattdessen die Desktop-App herunter.
README

pack "$APP_DIR" "$APP_TAR"
pack "$CLI_DIR" "$CLI_TAR"
rm -rf "$STAGE/bin"

# ---- manifest ---------------------------------------------------------------------------------
entry() { # entry <name> <kind>
    local file="$STAGE/$1"
    jq -n --arg name "$1" --arg os "$OS" --arg arch "$ARCH" --arg kind "$2" \
        --argjson size "$(stat -c %s "$file")" \
        --arg sha "$(sha256sum "$file" | cut -d' ' -f1)" \
        '{name: $name, os: $os, arch: $arch, kind: $kind, size: $size, sha256: $sha}'
}

jq -n \
    --arg app_version "$VERSION" --arg commit "$COMMIT" --argjson api_version "$API_VERSION" \
    --arg built_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --argjson app "$(entry "$APP_TAR" app)" --argjson cli "$(entry "$CLI_TAR" cli)" \
    '{app_version: $app_version, commit: $commit, api_version: $api_version, built_at: $built_at, files: [$app, $cli]}' \
    > "$STAGE/manifest.json"

# ---- publish: files first, manifest last ---------------------------------------------------------
mv -f "$STAGE/$APP_TAR" "$OUT/$APP_TAR"
mv -f "$STAGE/$CLI_TAR" "$OUT/$CLI_TAR"
chmod 0644 "$OUT/$APP_TAR" "$OUT/$CLI_TAR" "$STAGE/manifest.json"
mv -f "$STAGE/manifest.json" "$OUT/manifest.json"

# Prune what the new manifest no longer lists (older releases), after the switch.
for old in "$OUT"/lanomat-voice-*.tar.gz; do
    [ -e "$old" ] || continue
    name="$(basename "$old")"
    if [ "$name" != "$APP_TAR" ] && [ "$name" != "$CLI_TAR" ]; then
        rm -f "$old"
        echo "    pruned $name"
    fi
done

echo "==> Published to $OUT"
jq -r '.files[] | "    \(.name)  \(.size) bytes  sha256 \(.sha256)"' "$OUT/manifest.json"
