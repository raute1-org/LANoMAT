#!/bin/sh
# Stops what smoke-start.sh started (the app by its 15-character process name, then the rest).
T=${SMOKE_DIR:-/tmp/smoke}
pkill -x lanomat-voice-a 2>/dev/null
sleep 1
for f in standin xvfb; do
  [ -f "$T/$f.pid" ] && kill "$(cat "$T/$f.pid")" 2>/dev/null
done
pkill dbus-daemon 2>/dev/null
rm -rf "$T"
exit 0
