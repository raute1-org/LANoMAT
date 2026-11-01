#!/bin/sh
# Headless smoke, step 1: Xvfb, the stand-in server and the app, inside the app dev container.
# Needs LIVEKIT_API_KEY / LIVEKIT_API_SECRET (a `livekit-server --dev` on 127.0.0.1:7880) in the
# environment. Everything lives under $SMOKE_DIR (default /tmp/smoke). See clients/voice/README.md.
#
#   APPROVE_AFTER   seconds until the stand-in approves the device request (default 5)
#   STANDIN_ARGS    extra arguments for standin_server.py (e.g. --deny)
#   APP_BIN         app binary (default target/debug/lanomat-voice-app)
set -eu
T=${SMOKE_DIR:-/tmp/smoke}
APP_BIN=${APP_BIN:-target/debug/lanomat-voice-app}
rm -rf "$T"
mkdir -p "$T/cfg" "$T/run" "$T/peer-cfg/lanomat-voice" "$T/shots"
chmod 700 "$T/run"

Xvfb :99 -screen 0 1024x800x24 >"$T/xvfb.log" 2>&1 &
echo $! >"$T/xvfb.pid"

python3 -I app/tests/standin_server.py --port "${STANDIN_PORT:-8099}" \
  --peer-token-file "$T/peer-token" --approve-after "${APPROVE_AFTER:-5}" ${STANDIN_ARGS:-} \
  >"$T/standin.log" 2>&1 &
echo $! >"$T/standin.pid"

# The peer CLI is a second, pre-approved user in its own config directory.
sleep 1
printf '{"base_url":"http://127.0.0.1:%s","access_token":"%s","device_name":"peer"}' \
  "${STANDIN_PORT:-8099}" "$(cat "$T/peer-token")" >"$T/peer-cfg/lanomat-voice/credentials.json"
chmod 600 "$T/peer-cfg/lanomat-voice/credentials.json"

# PRE_SETTINGS: a settings.json to start with (e.g. the overlay switched on).
if [ -n "${PRE_SETTINGS:-}" ]; then
  mkdir -p "$T/cfg/lanomat-voice"
  printf '%s' "$PRE_SETTINGS" >"$T/cfg/lanomat-voice/settings.json"
fi
export DISPLAY=:99 GDK_BACKEND=x11 LIBGL_ALWAYS_SOFTWARE=1 WEBKIT_DISABLE_COMPOSITING_MODE=1 \
  WEBKIT_DISABLE_DMABUF_RENDERER=1 WEBKIT_DISABLE_SANDBOX_THIS_IS_DANGEROUS=1
export XDG_CONFIG_HOME="$T/cfg" XDG_RUNTIME_DIR="$T/run" XDG_SESSION_TYPE=x11
export HOSTNAME=demo-pc LANOMAT_VOICE_SYNTHETIC_AUDIO=1 RUST_LOG="${RUST_LOG:-lanomat_voice_app=info}"
unset WAYLAND_DISPLAY || true
sleep 1
dbus-run-session -- "$APP_BIN" >"$T/app.log" 2>&1 &
echo $! >"$T/app.pid"
echo "started: display :99, stand-in on ${STANDIN_PORT:-8099}, app pid $(cat "$T/app.pid")"
