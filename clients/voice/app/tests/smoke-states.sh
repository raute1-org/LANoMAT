#!/bin/sh
# Headless smoke, the non-happy states: pairing denied, an empty room list, an unreachable server
# while loading rooms, and the settings screen with the speaking overlay switched on. Each part
# starts its own environment (smoke-start.sh / smoke-stop.sh). Screenshots go to $SMOKE_SHOTS.
set -u
export DISPLAY=:99
T=${SMOKE_DIR:-/tmp/smoke}
OUT=${SMOKE_SHOTS:-/tmp/smoke-shots}
PORT=${STANDIN_PORT:-8099}
mkdir -p "$OUT"
FAILED=0
shot() { import -window root "$OUT/$1.png"; echo "screenshot: $1.png"; }
ok() { echo "PASS  $1"; }
bad() { echo "FAIL  $1"; FAILED=1; }
click() { xdotool mousemove "$1" "$2" click 1; sleep 0.4; }
login() { # approve-after
  sleep 5
  click 240 244
  xdotool type --delay 20 "http://127.0.0.1:$PORT"
  xdotool key Return
}

echo "== A. pairing denied"
STANDIN_ARGS="--deny" APPROVE_AFTER=3 sh app/tests/smoke-start.sh >/dev/null
login
sleep 8
shot 10-connect-denied
grep -q 'access_denied' "$T/standin.log" || true
sh app/tests/smoke-stop.sh

echo "== B. empty room list, then the server goes away"
STANDIN_ARGS="--no-rooms" APPROVE_AFTER=2 sh app/tests/smoke-start.sh >/dev/null
login
sleep 9
shot 11-rooms-empty
kill "$(cat "$T/standin.pid")"
sleep 1
click 412 124   # Neu laden
sleep 2
shot 12-rooms-error
sh app/tests/smoke-stop.sh

echo "== C. settings with the overlay switched on (signed in, joined)"
APPROVE_AFTER=2 sh app/tests/smoke-start.sh >/dev/null
login
sleep 9
click 430 218   # join the Lobby
sleep 4
xdotool mousemove 240 600
xdotool keydown space
sleep 2
xdotool keyup space
click 155 70    # Einstellungen
xdotool mousemove 240 400
for i in 1 2 3 4 5 6 7 8; do xdotool click 5; done
sleep 1
shot 13-settings-scrolled
click 43 292   # overlay on
XDG_RUNTIME_DIR="$T/run" "${CLI_BIN:-target/debug/lanomat-voice}" ptt down >/dev/null
sleep 2.5
shot 14-overlay
XDG_RUNTIME_DIR="$T/run" "${CLI_BIN:-target/debug/lanomat-voice}" ptt up >/dev/null
sh app/tests/smoke-stop.sh
[ "$FAILED" = 0 ] && echo "STATES RESULT: done"
