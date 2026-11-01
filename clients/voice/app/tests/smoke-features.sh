#!/bin/sh
# Headless self-test of the feature commands through the real Tauri IPC, against the stand-in
# server (standin_server.py --features): now feed, a chat round trip over a fake Pusher (HMAC
# checked), a download with progress and a tus upload with a forced mid-chunk drop that resumes.
# Needs no LiveKit server. Run inside the app dev container, after building with
#   cargo build -p lanomat-voice-app --features selftest
# Everything lives under $SMOKE_DIR (default /tmp/smoke-features). Exits non-zero on any failure.
set -eu
export PYTHONDONTWRITEBYTECODE=1
T=${SMOKE_DIR:-/tmp/smoke-features}
APP_BIN=${APP_BIN:-target/debug/lanomat-voice-app}
PORT=${STANDIN_PORT:-8097}
PUSHER=${PUSHER_PORT:-8096}
rm -rf "$T"
mkdir -p "$T/cfg/lanomat-voice" "$T/run" "$T/dl"
chmod 700 "$T/run"
FAILED=0
ok() { echo "PASS  $1"; }
bad() { echo "FAIL  $1"; FAILED=1; }
expect_log() { # file pattern description
  if grep -q -e "$2" "$1"; then ok "$3"; else bad "$3 (no '$2' in $1)"; fi
}

Xvfb :99 -screen 0 1024x800x24 >"$T/xvfb.log" 2>&1 &
XVFB=$!

# A source file whose size is not a multiple of the 1 MiB chunk size.
python3 -c "open('$T/upload.bin','wb').write(bytes(i % 251 for i in range(3 * 1024 * 1024 + 12345)))"

python3 app/tests/standin_server.py --port "$PORT" --features --pusher-port "$PUSHER" \
  --peer-token-file "$T/token" --tus-chunk 1048576 --tus-drop-after 300000 \
  --upload-source "$T/upload.bin" >"$T/standin.log" 2>&1 &
STANDIN=$!
for i in 1 2 3 4 5 6 7 8 9 10; do [ -s "$T/token" ] && break; sleep 0.5; done

# The app starts logged in: a credential file as `login` would have written it.
printf '{"base_url":"http://127.0.0.1:%s","access_token":"%s","device_name":"selftest"}' \
  "$PORT" "$(cat "$T/token")" >"$T/cfg/lanomat-voice/credentials.json"
chmod 600 "$T/cfg/lanomat-voice/credentials.json"

export DISPLAY=:99 GDK_BACKEND=x11 LIBGL_ALWAYS_SOFTWARE=1 WEBKIT_DISABLE_COMPOSITING_MODE=1 \
  WEBKIT_DISABLE_DMABUF_RENDERER=1 WEBKIT_DISABLE_SANDBOX_THIS_IS_DANGEROUS=1
export XDG_CONFIG_HOME="$T/cfg" XDG_RUNTIME_DIR="$T/run" XDG_SESSION_TYPE=x11
export HOSTNAME=selftest-pc LANOMAT_VOICE_SYNTHETIC_AUDIO=1 RUST_LOG="${RUST_LOG:-lanomat_voice_app=info}"
export LANOMAT_SELFTEST_JS=app/tests/selftest.js LANOMAT_SELFTEST_OUT="$T/report.txt"
export LANOMAT_SELFTEST_PARAMS="{\"downloadDir\":\"$T/dl\",\"uploadPath\":\"$T/upload.bin\",\"secretPath\":\"$T/cfg/lanomat-voice/credentials.json\"}"
unset WAYLAND_DISPLAY || true
sleep 1

dbus-run-session -- "$APP_BIN" >"$T/app.log" 2>&1 &
APP=$!
for i in $(seq 1 240); do
  kill -0 "$APP" 2>/dev/null || break
  sleep 1
done
if kill -0 "$APP" 2>/dev/null; then
  bad "the self-test did not finish within 240 s"
  kill -TERM "$APP" 2>/dev/null || true
fi
wait "$APP" 2>/dev/null && ok "self-test process exited 0" || bad "self-test process exit status"

echo "== report from the webview (IPC)"
cat "$T/report.txt" 2>/dev/null || bad "no report file"
if grep -q '^FAIL' "$T/report.txt" 2>/dev/null; then bad "a self-test step failed"; else ok "no failed step in the report"; fi
expect_log "$T/report.txt" 'SUMMARY failures=0' "the script ran to the end"

echo "== stand-in log (realtime lines)"
grep -E 'pusher|broadcasting' "$T/standin.log" || true
echo "== evidence on the stand-in side"
expect_log "$T/standin.log" 'pusher subscribed private-web-voice.channel.1 (signature verified' "private channel subscription authorised by HMAC through broadcasting/auth"
expect_log "$T/standin.log" 'pusher publish message.posted to 1 subscriber' "the posted message was pushed over the fake Pusher"
expect_log "$T/standin.log" 'download GET range=None auth=no' "download link fetched without the bearer"
expect_log "$T/standin.log" 'tus DROP' "the upload connection was cut mid-chunk"
expect_log "$T/standin.log" 'tus head offset=' "the client asked for the offset after the cut"
expect_log "$T/standin.log" 'tus complete .* matches_source=True' "the resumed upload is byte-identical to the source"
if [ "$(grep -c 'tus create' "$T/standin.log")" = 1 ]; then ok "exactly one tus upload was created (no restart from zero)"; else bad "tus create count"; fi
if [ "$(grep -c 'ticket issued' "$T/standin.log")" = 1 ]; then ok "exactly one ticket (the refused app file never asked for one)"; else bad "ticket count"; fi

echo "== files on disk"
python3 - "$T" <<'EOF' && ok "downloaded file is byte-identical (6 MiB pattern)" || bad "downloaded file differs"
import sys
d = open(sys.argv[1] + "/dl/maps.zip", "rb").read()
assert d == bytes(i % 251 for i in range(6 * 1024 * 1024)), len(d)
EOF
if ls "$T/dl" | grep -q '\.part$'; then bad "a .part file was left behind"; else ok "no .part file left behind"; fi
if grep -q 'lvc_' "$T/cfg/lanomat-voice/settings.json" 2>/dev/null; then bad "settings.json contains a token"; else ok "settings.json has no token"; fi
if grep -q "$(cat "$T/token")" "$T/app.log" "$T/report.txt" 2>/dev/null; then bad "the token appears in the app log or report"; else ok "the token appears neither in the app log nor in the report"; fi

kill "$STANDIN" "$XVFB" 2>/dev/null || true
echo
[ "$FAILED" = 0 ] && echo "FEATURE SMOKE RESULT: all checks passed" || echo "FEATURE SMOKE RESULT: FAILURES"
exit "$FAILED"
