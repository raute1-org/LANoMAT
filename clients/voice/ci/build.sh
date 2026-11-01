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
tar -C target/release -czf dist/linux/lanomat-voice-linux-x86_64.tar.gz lanomat-voice
tar -C target/release -czf dist/linux/lanomat-voice-app-linux-x86_64.tar.gz lanomat-voice-app

step "clippy (windows, cross)"
cargo xwin clippy --workspace --all-targets --all-features --target "$WIN" -- -D warnings

step "release build (windows, cross)"
cargo xwin build --release --target "$WIN" -p lanomat-voice-app -p voice-cli
cp "target/$WIN/release/lanomat-voice.exe" "target/$WIN/release/lanomat-voice-app.exe" dist/windows/

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
