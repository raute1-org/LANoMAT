#!/bin/sh
# The whole build, run inside the `windows` stage of Dockerfile.dev (workspace mounted at /work):
# format, lint and test for Linux, the Linux release binaries, then the same lint for Windows and
# the Windows release binaries plus the Windows test executables, all cross-compiled with
# cargo-xwin. Results land in /work/dist (linux/, windows/, windows-tests/).
#
# Used by .github/workflows/release-client.yml; runs the same way locally:
#   docker build --target windows -t lanomat-voice-build -f Dockerfile.dev .
#   docker run --rm -v "$PWD:/work" lanomat-voice-build sh ci/build.sh
set -eu
cd /work

WIN=x86_64-pc-windows-msvc

step() { printf '\n=== %s ===\n' "$*"; }

step "windows version mapping selftest"
ci/win-version.sh --selftest

step "cargo fmt --check"
cargo fmt --check

step "clippy (linux)"
cargo clippy --workspace --all-targets --all-features -- -D warnings

step "tests (linux)"
cargo test --workspace

step "release build (linux)"
cargo build --release -p lanomat-voice-app -p voice-cli

rm -rf dist
mkdir -p dist/linux dist/windows dist/windows-tests
# The version string is the server's (scripts/ops/lib/version.sh) and is also in the file names.
: "${LANOMAT_BUILD_VERSION:?LANOMAT_BUILD_VERSION is required}"
for kind in app cli; do
  dir="lanomat-voice-$kind-$LANOMAT_BUILD_VERSION-linux-x86_64"
  mkdir -p "dist/linux/$dir"
  if [ "$kind" = app ]; then
    install -m 0755 target/release/lanomat-voice-app "dist/linux/$dir/lanomat-voice-app"
    install -m 0644 app/icons/icon.png "dist/linux/$dir/lanomat-voice.png"
  else
    install -m 0755 target/release/lanomat-voice "dist/linux/$dir/lanomat-voice"
  fi
  tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner -C dist/linux -cf - "$dir" \
    | gzip -9 -n > "dist/linux/$dir.tar.gz"
  rm -rf "dist/linux/$dir"
done

step "clippy (windows, cross)"
cargo xwin clippy --workspace --all-targets --all-features --target "$WIN" -- -D warnings

step "release build (windows, cross): CLI"
cargo xwin build --release --target "$WIN" -p voice-cli
cp "target/$WIN/release/lanomat-voice.exe" dist/windows/

# The desktop app is built by the Tauri bundler: it compiles the app exe (the portable file) and
# wraps that same exe in an NSIS installer. The bundler is off in tauri.conf.json and is switched
# on for this one invocation, together with the numeric Windows version (ci/win-version.sh maps
# the release string, e.g. m12-496-g9624daa -> 0.496.0; the exact string stays in LANOMAT_BUILD_VERSION).
step "release build (windows, cross): desktop app and NSIS installer"
WINVER=$(ci/win-version.sh "$LANOMAT_BUILD_VERSION")
echo "Windows file version: $WINVER (from $LANOMAT_BUILD_VERSION)"
rm -rf "target/$WIN/release/bundle/nsis"
(cd app && cargo tauri build --runner cargo-xwin --target "$WIN" --bundles nsis --ci \
  --config "{\"version\":\"$WINVER\",\"bundle\":{\"active\":true}}")
cp "target/$WIN/release/lanomat-voice-app.exe" dist/windows/
setups=$(find "target/$WIN/release/bundle/nsis" -maxdepth 1 -name '*-setup.exe')
[ "$(printf '%s\n' "$setups" | wc -l)" = 1 ] || { echo "expected exactly one NSIS setup, got: $setups" >&2; exit 1; }
cp "$setups" dist/windows/lanomat-voice-app-setup.exe
echo "$WINVER" > dist/windows/windows-version.txt

step "windows test executables"
cargo xwin test --release --no-run --workspace --target "$WIN" --message-format=json \
  | python3 -c '
import json, sys
for line in sys.stdin:
    try:
        m = json.loads(line)
    except ValueError:
        continue
    if m.get("reason") == "compiler-artifact" and m.get("executable") and m.get("profile", {}).get("test"):
        print(m["executable"])
' | sort -u | while read -r exe; do
  cp "$exe" dist/windows-tests/
done
ls -l dist/windows-tests

step "result"
ls -lR dist
