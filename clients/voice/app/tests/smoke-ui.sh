#!/bin/sh
# Headless UI smoke: drives every participant screen of the desktop client through its real UI
# (ui-drive.js runs inside the app's webview, built with --features selftest) against the stand-in
# server in --ui-demo mode, takes screenshots of the normal, empty and error states and checks
# the evidence on the stand-in side. Run inside the app dev container:
#
#   cargo build -p lanomat-voice-app --features selftest
#   LIVEKIT_API_KEY=... LIVEKIT_API_SECRET=... sh app/tests/smoke-ui.sh        # all screens
#   MODE=mods sh app/tests/smoke-ui.sh                                          # modules off, orga role
#   MODE=narrow sh app/tests/smoke-ui.sh                                        # a 430 px wide window
#   MODE=dark sh app/tests/smoke-ui.sh                                          # dark colour scheme (GTK theme)
#   MODE=empty sh app/tests/smoke-ui.sh                                         # every screen with nothing to show
#   MODE=errors sh app/tests/smoke-ui.sh                                        # every screen with a failing server
#
# Needs a LiveKit dev server on 127.0.0.1:7880 (or LIVEKIT_URL) only for joining rooms (MODE=full).
# SMOKE_DISPLAY (default :99), STANDIN_PORT and PUSHER_PORT move the run aside from another one.
# Everything lives under $SMOKE_DIR; screenshots go to $SMOKE_SHOTS. Exits non-zero on a failure.
set -eu
export PYTHONDONTWRITEBYTECODE=1
MODE=${MODE:-full}
T=${SMOKE_DIR:-/tmp/smoke-ui-$MODE}
SHOTS=${SMOKE_SHOTS:-$T/shots}
APP_BIN=${APP_BIN:-target/debug/lanomat-voice-app}
PORT=${STANDIN_PORT:-8095}
PUSHER=${PUSHER_PORT:-8094}
DSP=${SMOKE_DISPLAY:-:99}
LK_URL=${LIVEKIT_URL:-ws://127.0.0.1:7880}
rm -rf "$T"
mkdir -p "$T/cfg/lanomat-voice" "$T/run" "$T/dl" "$SHOTS" "$T/bin"
chmod 700 "$T/run"
FAILED=0
ok() { echo "PASS  $1"; }
bad() { echo "FAIL  $1"; FAILED=1; }
expect_log() { # file pattern description
  if grep -q -e "$2" "$1"; then ok "$3"; else bad "$3 (no '$2' in $1)"; fi
}

Xvfb "$DSP" -screen 0 1024x800x24 >"$T/xvfb.log" 2>&1 &
XVFB=$!

python3 -c "open('$T/upload.bin','wb').write(bytes(i % 251 for i in range(3 * 1024 * 1024 + 12345)))"

EXTRA=""
[ "$MODE" = mods ] && EXTRA="--role orga --modules-off catering,files,friends,presence,identity"
[ "$MODE" = empty ] && EXTRA="--scenario empty"
[ "$MODE" = errors ] && EXTRA="--scenario error"
# The dark scheme follows the GTK theme in WebKitGTK.
[ "$MODE" = dark ] && export GTK_THEME=Adwaita:dark
python3 -I app/tests/standin_server.py --port "$PORT" --features --ui-demo --livekit-url "$LK_URL" --pusher-port "$PUSHER" \
  --peer-token-file "$T/token" --tus-chunk 1048576 --tus-drop-after 300000 --approve-after 2 \
  --upload-source "$T/upload.bin" $EXTRA >"$T/standin.log" 2>&1 &
STANDIN=$!
for i in 1 2 3 4 5 6 7 8 9 10; do [ -s "$T/token" ] && break; sleep 0.5; done

if [ "$MODE" != full ]; then
  # Already paired: these runs start straight in the tabs.
  printf '{"base_url":"http://127.0.0.1:%s","access_token":"%s","device_name":"demo-pc"}' \
    "$PORT" "$(cat "$T/token")" >"$T/cfg/lanomat-voice/credentials.json"
  chmod 600 "$T/cfg/lanomat-voice/credentials.json"
fi

export DISPLAY="$DSP" GDK_BACKEND=x11 LIBGL_ALWAYS_SOFTWARE=1 WEBKIT_DISABLE_COMPOSITING_MODE=1 \
  WEBKIT_DISABLE_DMABUF_RENDERER=1 WEBKIT_DISABLE_SANDBOX_THIS_IS_DANGEROUS=1
export XDG_CONFIG_HOME="$T/cfg" XDG_RUNTIME_DIR="$T/run" XDG_SESSION_TYPE=x11
# A neutral host name: the device name of a pairing and the screenshots must not leak a real one.
# A stand-in for the system's URL handler: it only records what the app asked it to open, so the
# smoke can show exactly which (rewritten) link left the app. Nothing is actually launched.
printf '#!/bin/sh\necho "$1" >>"%s/opened.log"\n' "$T" >"$T/bin/xdg-open"
chmod +x "$T/bin/xdg-open"
export PATH="$T/bin:$PATH"
export HOSTNAME=demo-pc LANOMAT_VOICE_SYNTHETIC_AUDIO=1 RUST_LOG="${RUST_LOG:-lanomat_voice_app=info}"
export LANOMAT_SELFTEST_JS=app/tests/ui-drive.js LANOMAT_SELFTEST_OUT="$T/report.txt"
export LANOMAT_SELFTEST_HOOK=app/tests/ui-hook.sh STANDIN_PORT="$PORT" SMOKE_SHOTS="$SHOTS"
export LANOMAT_SELFTEST_PARAMS="{\"mode\":\"$MODE\",\"serverUrl\":\"http://127.0.0.1:$PORT\",\"uploadPath\":\"$T/upload.bin\",\"downloadDir\":\"$T/dl\"}"
unset WAYLAND_DISPLAY || true
sleep 1

# The download folder is a setting: write it before the start.
printf '{"download_dir":"%s"}' "$T/dl" >"$T/cfg/lanomat-voice/settings.json"

dbus-run-session -- "$APP_BIN" >"$T/app.log" 2>&1 &
APP=$!
for i in $(seq 1 420); do
  kill -0 "$APP" 2>/dev/null || break
  sleep 1
done
if kill -0 "$APP" 2>/dev/null; then
  bad "the drive did not finish within 420 s"
  kill -TERM "$APP" 2>/dev/null || true
fi
wait "$APP" 2>/dev/null && ok "drive process exited 0" || bad "drive process exit status"

echo "== report from the webview"
cat "$T/report.txt" 2>/dev/null || bad "no report file"
if grep -q '^FAIL' "$T/report.txt" 2>/dev/null; then bad "a drive step failed"; else ok "no failed step in the report"; fi
expect_log "$T/report.txt" 'SUMMARY failures=0' "the drive ran to the end"

if [ "$MODE" = full ]; then
  echo "== evidence on the stand-in side"
  expect_log "$T/standin.log" 'pusher publish message.posted to 1 subscriber' "a chat message was pushed to the app over the fake Pusher"
  expect_log "$T/standin.log" 'tus DROP' "the upload connection was cut mid-chunk"
  expect_log "$T/standin.log" 'tus complete .* matches_source=True' "the resumed upload is byte-identical to the source"
  expect_log "$T/standin.log" 'download GET range=None auth=no' "the download was fetched without the bearer"
  expect_log "$T/standin.log" 'POST /api/voice-client/v1/polls/3/vote' "the vote reached the server"
  expect_log "$T/standin.log" 'POST /api/voice-client/v1/food-orders/4/items' "a food item was placed"
  expect_log "$T/standin.log" 'POST /api/voice-client/v1/rooms/3/token' "the match room was joined (room token requested)"
  expect_log "$T/standin.log" 'POST /api/voice-client/v1/rooms/3/messages' "a chat message was sent"
  expect_log "$T/standin.log" 'POST /api/voice-client/v1/rooms/1/token' "following Ada into the web voice room joined it (room token requested)"
  expect_log "$T/standin.log" 'GET /api/voice-client/v1/events/lan-party-2026/people/12/follow' "the follow targets of a teammate were asked for"
  expect_log "$T/standin.log" 'PATCH /api/voice-client/v1/profile' "the profile was saved with PATCH"
  expect_log "$T/standin.log" 'POST /api/voice-client/v1/connections/twitch/link-url' "a single-use link was requested for Twitch"
  expect_log "$T/standin.log" 'POST /api/voice-client/v1/friends/requests/501/accept' "a friend request was accepted"
  expect_log "$T/standin.log" 'POST /api/voice-client/v1/users/17/block' "a friend was blocked"
  expect_log "$T/opened.log" '^mumble://Tester@voice.example:64738/Team%20Nachtschicht$' "the Mumble link was rewritten (no space) before it was opened"
  expect_log "$T/opened.log" '^steam://connect/203.0.113.5:27015$' "the game link of a teammate was opened"
  expect_log "$T/opened.log" '^http://127.0.0.1:[0-9]*/settings/connections/link/demo-link-1$' "the single-use link went to the system browser (checked by the real open_link_url)"
  if grep -q 'discord.com' "$T/opened.log" 2>/dev/null; then bad "a Discord link was opened (it must only be copied)"; else ok "no Discord link was opened"; fi
  if grep -q 'demo-link' "$T/app.log" "$T/report.txt" 2>/dev/null; then bad "the single-use link appears in the app log or report"; else ok "the single-use link appears neither in the app log nor in the report"; fi
  [ -f "$T/dl/maps.zip" ] && ok "the downloaded file is on disk" || bad "the downloaded file is missing"
  if grep -q "$(cat "$T/token")" "$T/app.log" "$T/report.txt" 2>/dev/null; then bad "the token appears in the app log or report"; else ok "the token appears neither in the app log nor in the report"; fi
fi

kill "$STANDIN" "$XVFB" 2>/dev/null || true
echo
[ "$FAILED" = 0 ] && echo "UI SMOKE RESULT: all checks passed" || echo "UI SMOKE RESULT: FAILURES"
exit "$FAILED"
