#!/bin/sh
# The Linux build, run inside the `app` stage of Dockerfile.dev (workspace mounted at /work):
# format, lint and test the Rust core, then build the Tauri desktop app and stage the Linux
# release tarball under /work/dist/linux. The Windows cross-build is a separate job in
# .github/workflows/release-launcher.yml (it uses the `windows` stage and `cargo xwin`).
#
# Used by .github/workflows/release-launcher.yml; runs the same way locally:
#   docker build --target app -t lanomat-launcher-dev -f clients/launcher/Dockerfile.dev clients/launcher
#   docker run --rm -e LANOMAT_BUILD_VERSION=dev -v "$PWD/clients/launcher:/work" \
#     lanomat-launcher-dev sh ci/build.sh
#
# LANOMAT_BUILD_VERSION names the staged files and LIESMICH.txt only. The binary keeps Cargo's
# package version (0.1.0) plus LANOMAT_LAUNCHER_COMMIT: this script does not change what the app
# reports, and scripts/ops/build-launcher.sh compiles the same way.
set -eu
cd /work

step() { printf '\n=== %s ===\n' "$*"; }

: "${LANOMAT_BUILD_VERSION:?LANOMAT_BUILD_VERSION is required}"

# Same rule as lanomat_version_ok in scripts/ops/lib/version.sh: a safe version string
# (also a safe file-name part).
case "$LANOMAT_BUILD_VERSION" in
    '' | *[!A-Za-z0-9._-]*)
        echo "unsafe version string: '$LANOMAT_BUILD_VERSION'" >&2
        exit 1
        ;;
esac
case "$LANOMAT_BUILD_VERSION" in
    [A-Za-z0-9]*) ;;
    *)
        echo "version must start with a letter or digit: '$LANOMAT_BUILD_VERSION'" >&2
        exit 1
        ;;
esac
if [ "${#LANOMAT_BUILD_VERSION}" -gt 64 ]; then
    echo "version is longer than 64 characters: '$LANOMAT_BUILD_VERSION'" >&2
    exit 1
fi

step "cargo fmt --check"
cargo fmt --all --check

step "clippy (launcher-core + app)"
cargo clippy --locked --workspace --all-targets -- -D warnings

step "tests (workspace: launcher-core + app)"
# --workspace, not just -p launcher-core: the app crate carries real unit tests (slug validation,
# URL schemes, the footer's version/update parsing) that no other job runs.
cargo test --locked --workspace

step "release build (linux): lanomat-launcher"
cargo build --locked --release -p lanomat-launcher-app
[ -x target/release/lanomat-launcher ] || {
    echo "the release build produced no binary at target/release/lanomat-launcher" >&2
    exit 1
}

step "stage the Linux tarball"
rm -rf dist
dir="lanomat-launcher-$LANOMAT_BUILD_VERSION-linux-x86_64"
# Explicit mode: the tarball records this directory, so the archive must not depend on the umask.
# Two steps so the mode applies to the packaged directory only (mkdir -p -m masks the parents).
mkdir -p dist/linux
mkdir -m 0755 "dist/linux/$dir"
install -m 0755 target/release/lanomat-launcher "dist/linux/$dir/lanomat-launcher"
# 256x256: the hicolor size scripts/install-desktop.sh installs into. scripts/ops/build-launcher.sh
# scales the same source with the same pinned image, so both packagers ship equal bytes.
convert app/icons/icon.png -resize 256x256 -strip "dist/linux/$dir/lanomat-launcher.png"

# The desktop entry and the readme come from clients/launcher/scripts (the same files
# scripts/ops/build-launcher.sh uses): install-desktop.sh renders the entry with
# `--print-desktop` and rewrites Exec= to the absolute binary path, so a lanomat:// link from
# the website finds the launcher. liesmich.txt is filled in here. install-terminal.sh stays next
# to install-desktop.sh: it calls it as its sibling for arcade/gaming-terminal spin-up.
sh scripts/install-desktop.sh --print-desktop > "dist/linux/$dir/lanomat-launcher.desktop"
chmod 0644 "dist/linux/$dir/lanomat-launcher.desktop"
install -m 0755 scripts/install-desktop.sh "dist/linux/$dir/install-desktop.sh"
install -m 0755 scripts/install-terminal.sh "dist/linux/$dir/install-terminal.sh"

sed -e "s|@VERSION@|$LANOMAT_BUILD_VERSION|g" \
    -e "s|@COMMIT@|${LANOMAT_BUILD_COMMIT:-unknown}|g" \
    -e "s|@PKG@|$dir|g" \
    -e "s|@ARCHIVE@|$dir.tar.gz|g" \
    scripts/liesmich.txt > "dist/linux/$dir/LIESMICH.txt"
chmod 0644 "dist/linux/$dir/LIESMICH.txt"

# Reproducible: sorted entries, fixed mtime, root ownership, no gzip timestamp.
tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner \
    -C dist/linux -cf - "$dir" | gzip -9 -n > "dist/linux/$dir.tar.gz"
rm -rf "dist/linux/$dir"

step "result"
ls -lR dist
