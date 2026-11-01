#!/bin/sh
# Headless smoke, step 2: drive the running app like a person (xdotool on the Xvfb display) and
# through its control socket, with a second user (the peer CLI) in the same room, and check what
# the SFU delivered. Prints evidence; exits non-zero when an expectation fails. Run after
# smoke-start.sh, inside the same container. Screenshots land in $SMOKE_DIR/shots.
set -u
T=${SMOKE_DIR:-/tmp/smoke}
export DISPLAY="${SMOKE_DISPLAY:-:99}"
CLI=${CLI_BIN:-target/debug/lanomat-voice}
# The app's own environment, so `ptt` finds the app's control socket.
export XDG_RUNTIME_DIR="$T/run"
FAILED=0

shot() { import -window root -crop 960x760+0+0 +repage "$T/shots/v$1.png"; echo "screenshot: v$1.png"; }
ok() { echo "PASS  $1"; }
bad() { echo "FAIL  $1"; FAILED=1; }
expect_log() { # file pattern description
  if grep -q -e "$2" "$1"; then ok "$3"; else bad "$3 (no '$2' in $1)"; fi
}
click() { xdotool mousemove "$1" "$2" click 1; sleep 0.4; }

echo "== 1. connect screen"
shot 01-connect
click 480 244   # the address field
sleep 1
xdotool type --delay 60 "http://127.0.0.1:${STANDIN_PORT:-8099}"
xdotool key Return
sleep 2
shot 02-connect-wait

echo "== 2. approval on the stand-in (it approves after a few seconds), then the room list"
for i in $(seq 1 30); do
  grep -q 'v1/rooms' "$T/standin.log" && break
  sleep 1
done
sleep 1
click 285 439   # a fresh pairing opens the first-run guide: Ueberspringen
sleep 1
expect_log "$T/standin.log" 'POST /api/voice-client/token HTTP/1.1" 200' "device flow approved and token issued"
expect_log "$T/standin.log" 'GET /api/voice-client/v1/rooms' "room list fetched with the bearer token"
shot 03-rooms
if grep -q 'lvc_' "$T/cfg/lanomat-voice/settings.json"; then bad "settings.json contains a token"; else ok "settings.json has no token"; fi
[ "$(stat -c %a "$T/cfg/lanomat-voice/credentials.json")" = 600 ] && ok "credentials.json is mode 600" || bad "credentials.json mode"

echo "== 3. the peer joins the Lobby first (second user, CLI)"
mkdir -p "$T/peer-run" && chmod 700 "$T/peer-run"
XDG_CONFIG_HOME="$T/peer-cfg" XDG_RUNTIME_DIR="$T/peer-run" "$CLI" join Lobby --synthetic-audio \
  >"$T/peer.log" 2>&1 &
echo $! >"$T/peer.pid"
sleep 4
expect_log "$T/peer.log" 'Joined "Lobby" as u2' "peer joined the Lobby as u2"

echo "== 4. join the Lobby in the app (tab Sprache, click Beitreten)"
click 129 72
sleep 1
click 405 298
sleep 4
shot 04-room-muted
expect_log "$T/peer.log" '+ u1 joined' "peer saw the app user (u1) join"
RESP=$("$CLI" ptt status 2>&1)
[ "$RESP" = muted ] && ok "control socket: status = muted after join" || bad "ptt status after join: $RESP"

echo "== 5. push-to-talk through the control socket (what a Wayland compositor bind does)"
R=$("$CLI" ptt down 2>&1); [ "$R" = talking ] && ok "ptt down -> talking" || bad "ptt down -> $R"
sleep 3
shot 05-talking
R=$("$CLI" ptt up 2>&1); [ "$R" = muted ] && ok "ptt up -> muted" || bad "ptt up -> $R"
sleep 2
shot 06-muted-again
expect_log "$T/peer.log" 'u1 unmuted' "SFU delivered the unmute to the peer"
expect_log "$T/peer.log" 'u1 muted' "SFU delivered the mute to the peer"
expect_log "$T/peer.log" 'speaking: u1' "peer saw the app user as active speaker"

echo "== 6. hold the on-screen button with the mouse"
xdotool mousemove 240 320 mousedown 1
sleep 2
R=$("$CLI" ptt status 2>&1); [ "$R" = talking ] && ok "mouse hold -> talking" || bad "mouse hold -> $R"
shot 07-mouse-hold
xdotool mouseup 1
sleep 1
R=$("$CLI" ptt status 2>&1); [ "$R" = muted ] && ok "mouse release -> muted" || bad "mouse release -> $R"

echo "== 7. hold Space (keyboard)"
xdotool mousemove 240 600
xdotool keydown space
sleep 1.5
R=$("$CLI" ptt status 2>&1); [ "$R" = talking ] && ok "space hold -> talking" || bad "space hold -> $R"
xdotool keyup space
sleep 1
R=$("$CLI" ptt status 2>&1); [ "$R" = muted ] && ok "space release -> muted" || bad "space release -> $R"

echo "== 8. settings screen (tab Mehr, Einstellungen)"
click 364 72
sleep 1
click 400 417
sleep 1
shot 08-settings

echo "== 8b. global shortcut Control+Alt+Space (X11 grab on Xvfb; XTEST key events), both release orders"
xdotool keydown ctrl keydown alt keydown space
sleep 1
R=$("$CLI" ptt status 2>&1); [ "$R" = talking ] && ok "shortcut press -> talking" || bad "shortcut press -> $R"
xdotool keyup space
sleep 1
R=$("$CLI" ptt status 2>&1); [ "$R" = muted ] && ok "shortcut release (space first) -> muted" || bad "shortcut release (space first) -> $R"
xdotool keyup alt keyup ctrl
sleep 0.5
xdotool keydown ctrl keydown alt keydown space
sleep 1
xdotool keyup ctrl keyup alt
sleep 0.5
xdotool keyup space
sleep 1
R=$("$CLI" ptt status 2>&1); [ "$R" = muted ] && ok "shortcut release (modifiers first) -> muted" || bad "shortcut release (modifiers first) -> $R (stuck open mic)"
"$CLI" ptt up >/dev/null 2>&1

echo "== 9. closing the window hides it to the tray, the app keeps running"
xdotool search --onlyvisible --name 'LANoMAT Voice' >"$T/visible.before" 2>/dev/null
WID=$(head -1 "$T/visible.before")
python3 -I app/tests/x11_close.py "$WID"
sleep 1
xdotool search --onlyvisible --name 'LANoMAT Voice' >"$T/visible.after" 2>/dev/null
[ ! -s "$T/visible.after" ] && ok "window hidden" || bad "window still visible"
R=$("$CLI" ptt status 2>&1); [ "$R" = muted ] && ok "app still alive, control socket answers" || bad "after hide: $R"
pgrep -x lanomat-voice-a >/dev/null && ok "process still running" || bad "process gone"

echo "== 10. SIGTERM: clean exit, socket removed, peer sees the user leave"
APP=$(pgrep -x lanomat-voice-a | tail -1)  # newest (earlier runs may leave zombies)
kill -TERM "$APP"
for i in 1 2 3 4 5 6 7 8 9 10; do kill -0 "$APP" 2>/dev/null || break; sleep 0.5; done
kill -0 "$APP" 2>/dev/null && bad "app did not exit after SIGTERM" || ok "app exited after SIGTERM"
[ ! -e "$T/run/lanomat-voice.sock" ] && ok "control socket removed" || bad "control socket left behind"
sleep 1
expect_log "$T/peer.log" '- u1 left' "peer saw u1 leave the room"
kill -TERM "$(cat "$T/peer.pid")" 2>/dev/null

echo
[ "$FAILED" = 0 ] && echo "SMOKE RESULT: all checks passed" || echo "SMOKE RESULT: FAILURES"
exit "$FAILED"
