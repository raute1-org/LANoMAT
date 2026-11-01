#!/bin/sh
# Hook for the UI drive (ui-drive.js): the app's `selftest_hook` command runs this script, because
# a script in the webview can neither take a screenshot nor switch the stand-in's scenario.
#   ui-hook.sh shot NAME                 screenshot of the main window to $SMOKE_SHOTS/NAME.png
#   ui-hook.sh key KEY...                real key events through xdotool (focus is in the webview)
#   ui-hook.sh resize WIDTH HEIGHT       resize the main window
#   ui-hook.sh scenario NAME             GET /__scenario/NAME on the stand-in (normal, empty, error, offline)
#   ui-hook.sh chat ROOM AUTHOR BODY...  a second user writes in a room (published over the fake Pusher)
#   ui-hook.sh uploaded                  names of the files the stand-in holds
#   ui-hook.sh hostget PATH              GET /PATH on the stand-in (a __ hook such as __me/location/off)
# Environment: DISPLAY, STANDIN_PORT, SMOKE_SHOTS.
PORT=${STANDIN_PORT:-8099}
OUT=${SMOKE_SHOTS:-/tmp/smoke-ui-shots}
cmd=$1
shift
case "$cmd" in
  shot)
    mkdir -p "$OUT"
    wid=$(xdotool search --onlyvisible --name '^LANoMAT Voice$' | head -1)
    # A palette PNG without dithering keeps the files small; the UI has flat colours.
    import -window "${wid:-root}" -dither None -colors 160 -define png:compression-level=9 "PNG8:$OUT/$1.png"
    echo "shot $1"
    ;;
  key)
    xdotool key "$@"
    sleep 0.4
    echo "key $*"
    ;;
  resize)
    wid=$(xdotool search --onlyvisible --name '^LANoMAT Voice$' | head -1)
    xdotool windowsize "$wid" "$1" "$2"
    sleep 0.5
    echo "resized to $1x$2"
    ;;
  scenario)
    curl -fsS "http://127.0.0.1:$PORT/__scenario/$1"
    ;;
  chat)
    room=$1
    author=$2
    shift 2
    curl -fsS -G "http://127.0.0.1:$PORT/__chat" --data-urlencode "room=$room" \
      --data-urlencode "as=$author" --data-urlencode "body=$*"
    ;;
  uploaded)
    curl -fsS "http://127.0.0.1:$PORT/__uploaded"
    ;;
  hostget)
    curl -fsS "http://127.0.0.1:$PORT/$1"
    ;;
  *)
    echo "unknown hook command: $cmd"
    ;;
esac
