#!/bin/sh
# Headless shell smoke of the LANoMAT Launcher (Tauri v2) against a stub LANoMAT server.
#
# Proves, on a real X display (Xvfb) with the real binary, that:
#   1. the connect screen renders: typing a server address into it makes the shell start the
#      device flow (the stub sees POST /api/mcp/device with the device name),
#   2. pairing completes and is stored: the stub issues the token, the shell reads /api/v1/me and
#      writes config.json (0600) with the address and the token, then loads the library,
#   3. a pre-seeded config boots straight into the library: GET /api/v1/recipes is sent with the
#      stored bearer token (the UI asked for the cards, so the UT2004 card is on screen),
#   4. a second instance exits (code 0) and hands its lanomat://install/<slug> link to the first,
#      which fetches the UT2004 plan (the headless proof that the card was found and opened),
#      and the first instance survives the failed install (no crash, no panic).
#
# Run inside the app stage of Dockerfile.dev (mount clients/launcher at /work):
#
#   docker run --rm -v "$PWD/clients/launcher:/work" -w /work \
#     -e SMOKE_DIR=/work/target/smoke-shell lanomat-launcher-dev sh app/tests/smoke-shell.sh
#
# Options (environment):
#   SKIP_BUILD=1        use the existing $APP_BIN instead of `cargo build -p lanomat-launcher-app`
#   APP_BIN=...          default $CARGO_TARGET_DIR/debug/lanomat-launcher (target/debug/...)
#   SMOKE_DIR=...        work dir, default /tmp/lanomat-launcher-smoke; screenshots go to $SMOKE_DIR/shots
#   SMOKE_DISPLAY=:99    use that display instead of picking a free one
#   STANDIN_PORT=...     pin the stub port (default: a free port)
#   SMOKE_TITLE=...      window title to look for, default "LANoMAT Launcher"
#
# Fixed sleeps only sit inside bounded wait loops; every wait has a timeout and a failure message
# that names the log to read. Exits non-zero when any assertion fails. The stub request log
# ($SMOKE_DIR/logs/standin.log) and the app logs ($SMOKE_DIR/logs/app-*.log) are the evidence.
set -u
export PYTHONDONTWRITEBYTECODE=1

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
WORKSPACE=$(dirname -- "$(dirname -- "$HERE")")
cd "$WORKSPACE" || exit 2

T=${SMOKE_DIR:-/tmp/lanomat-launcher-smoke}
SHOTS=${SMOKE_SHOTS:-$T/shots}
LOGS=$T/logs
CONFIG_DIR=$T/config
TARGET_DIR=${CARGO_TARGET_DIR:-$WORKSPACE/target}
APP_BIN=${APP_BIN:-$TARGET_DIR/debug/lanomat-launcher}
TITLE=${SMOKE_TITLE:-LANoMAT Launcher}
STANDIN=$HERE/fixtures/standin_launcher.py
X11_CLOSE=$HERE/fixtures/x11_close.py
INSPECTOR=$HERE/fixtures/inspector_probe.py
SKIP_BUILD=${SKIP_BUILD:-0}
STANDIN_LOG=$LOGS/standin.log

PASSED=0
FAILED=0
APP_PID=""
APP_WID=""
XVFB_PID=""
STANDIN_PID=""
DBUS_PID=""
DSP=""

pass() { PASSED=$((PASSED + 1)); echo "PASS  $1"; }
fail() { FAILED=$((FAILED + 1)); echo "FAIL  $1"; }
skip() { echo "SKIP  $1"; }

tail_log() { # file
    [ -s "$1" ] || return 0
    echo "--- last lines of $1"
    tail -n 15 "$1"
}

fatal() {
    echo "FATAL $1"
    exit 2
}

CLEANED=0
cleanup() {
    [ "$CLEANED" = 1 ] && return 0
    CLEANED=1
    if [ -n "$APP_PID" ] && kill -0 "$APP_PID" 2>/dev/null; then
        kill "$APP_PID" 2>/dev/null
    fi
    [ -n "${SECOND_PID:-}" ] && kill "$SECOND_PID" 2>/dev/null
    [ -n "$STANDIN_PID" ] && kill "$STANDIN_PID" 2>/dev/null
    [ -n "$DBUS_PID" ] && kill "$DBUS_PID" 2>/dev/null
    [ -n "$XVFB_PID" ] && kill "$XVFB_PID" 2>/dev/null
    wait 2>/dev/null
}
trap cleanup EXIT
trap 'exit 130' INT TERM

rm -rf "$T"
mkdir -p "$T/home" "$T/cfg" "$T/run" "$SHOTS" "$LOGS" "$CONFIG_DIR"
chmod 700 "$T/home" "$T/run"

# ---- display ------------------------------------------------------------------------------------

display_free() { # number
    [ ! -e "/tmp/.X11-unix/X$1" ] && [ ! -e "/tmp/.X$1-lock" ]
}

start_xvfb() { # display
    Xvfb "$1" -screen 0 "${SMOKE_SCREEN:-1024x800x24}" -nolisten tcp >"$LOGS/xvfb.log" 2>&1 &
    XVFB_PID=$!
    i=0
    while [ "$i" -lt 25 ]; do
        if xdpyinfo -display "$1" >/dev/null 2>&1; then
            return 0
        fi
        kill -0 "$XVFB_PID" 2>/dev/null || return 1
        i=$((i + 1))
        sleep 0.2
    done
    return 1
}

if [ -n "${SMOKE_DISPLAY:-}" ]; then
    DSP=$SMOKE_DISPLAY
    if ! xdpyinfo -display "$DSP" >/dev/null 2>&1; then
        start_xvfb "$DSP" || fatal "Xvfb did not come up on $DSP (see $LOGS/xvfb.log)"
    fi
else
    n=99
    while [ "$n" -lt 140 ]; do
        if display_free "$n" && start_xvfb ":$n"; then
            DSP=":$n"
            break
        fi
        kill "$XVFB_PID" 2>/dev/null
        XVFB_PID=""
        n=$((n + 1))
    done
    [ -n "$DSP" ] || fatal "no free X display in :99..:139"
fi
echo "display $DSP, workspace $WORKSPACE, artifacts $T"

# ---- stub server --------------------------------------------------------------------------------

free_port() {
    python3 -c 'import socket
s = socket.socket()
s.bind(("127.0.0.1", 0))
print(s.getsockname()[1])
s.close()'
}

PORT=${STANDIN_PORT:-$(free_port)}
DEVPORT=$(free_port)
[ -n "$PORT" ] || fatal "could not pick a stub port"
BASE_URL="http://127.0.0.1:$PORT"

python3 -I "$STANDIN" --port "$PORT" --log "$STANDIN_LOG" >"$LOGS/standin.out" 2>&1 &
STANDIN_PID=$!

i=0
STANDIN_READY=0
while [ "$i" -lt 50 ]; do
    if grep -q '^READY ' "$LOGS/standin.out" 2>/dev/null; then
        STANDIN_READY=1
        break
    fi
    i=$((i + 1))
    sleep 0.2
done
if [ "$STANDIN_READY" != 1 ]; then
    tail_log "$LOGS/standin.out"
    fatal "stub server did not start (see $LOGS/standin.out)"
fi
echo "stub server $BASE_URL"

# ---- a private session bus (single instance is a D-Bus name on Linux) ----------------------------

BUS_INFO=$(dbus-daemon --session --fork --print-address=1 --print-pid=1) ||
    fatal "dbus-daemon did not start"
DBUS_SESSION_BUS_ADDRESS=$(printf '%s\n' "$BUS_INFO" | sed -n 1p)
DBUS_PID=$(printf '%s\n' "$BUS_INFO" | sed -n 2p)
export DBUS_SESSION_BUS_ADDRESS
[ -n "$DBUS_PID" ] || fatal "dbus-daemon printed no pid"

# ---- the app's environment ----------------------------------------------------------------------

export DISPLAY="$DSP"
export HOME="$T/home" XDG_CONFIG_HOME="$T/cfg" XDG_RUNTIME_DIR="$T/run" XDG_SESSION_TYPE=x11
export HOSTNAME=demo-pc
export LANOMAT_LAUNCHER_CONFIG_DIR="$CONFIG_DIR"
export GDK_BACKEND=x11 LIBGL_ALWAYS_SOFTWARE=1 WEBKIT_DISABLE_COMPOSITING_MODE=1 \
    WEBKIT_DISABLE_DMABUF_RENDERER=1 WEBKIT_DISABLE_SANDBOX_THIS_IS_DANGEROUS=1
export WEBKIT_INSPECTOR_SERVER="127.0.0.1:$DEVPORT"
export RUST_LOG=${RUST_LOG:-lanomat=debug,lanomat_launcher_app=info}
unset WAYLAND_DISPLAY || true

# ---- helpers ------------------------------------------------------------------------------------

window_id() {
    xdotool search --onlyvisible --name "^$TITLE" 2>/dev/null | head -1 || true
}

window_count() {
    xdotool search --onlyvisible --name "^$TITLE" 2>/dev/null | wc -l | tr -d ' ' || echo 0
}

wait_window() { # seconds
    i=0
    while [ "$i" -lt "$1" ]; do
        wid=$(window_id)
        if [ -n "$wid" ]; then
            echo "$wid"
            return 0
        fi
        i=$((i + 1))
        sleep 1
    done
    return 1
}

process_dead() { # pid
    [ -e "/proc/$1" ] || return 0
    grep -q '^State:[[:space:]]*Z' "/proc/$1/status" 2>/dev/null
}

wait_for_process_exit() { # pid seconds
    i=0
    limit=$(( $2 * 4 ))
    while [ "$i" -lt "$limit" ]; do
        process_dead "$1" && return 0
        i=$((i + 1))
        sleep 0.25
    done
    return 1
}

expect_log_wait() { # file pattern seconds description
    pattern=$2
    i=0
    while [ "$i" -lt "$3" ]; do
        if grep -q -e "$pattern" "$1" 2>/dev/null; then
            pass "$4"
            return 0
        fi
        i=$((i + 1))
        sleep 1
    done
    fail "$4 (no '$pattern' in $1 within $3 s)"
    return 1
}

expect_log() { # file pattern description
    if grep -q -e "$2" "$1" 2>/dev/null; then
        pass "$3"
        return 0
    fi
    fail "$3 (no '$2' in $1)"
    return 1
}

log_wait() { # file pattern seconds  (quiet: no PASS/FAIL, for a retry decision)
    i=0
    while [ "$i" -lt "$3" ]; do
        grep -q -e "$2" "$1" 2>/dev/null && return 0
        i=$((i + 1))
        sleep 1
    done
    return 1
}

shot() { # name
    wid=$(window_id)
    if [ -z "$wid" ]; then
        fail "screenshot $1: no window '$TITLE'"
        return 1
    fi
    if ! import -window "$wid" -dither None -colors 200 "PNG8:$SHOTS/$1.png" >/dev/null 2>&1; then
        import -window "$wid" "$SHOTS/$1.png" >/dev/null 2>&1 ||
            { fail "screenshot $1: import failed"; return 1; }
    fi
    echo "screenshot: $SHOTS/$1.png"
    return 0
}

screen_probe() { # png; 0 when it looks like a rendered window, sets SCREEN_INFO
    png=$1
    SCREEN_INFO=""

    if [ ! -s "$png" ]; then
        SCREEN_INFO="no screenshot"
        return 1
    fi

    dims=$(identify -format '%k %w %h' "$png" 2>/dev/null) || dims=""

    case "$dims" in
        '' | *[!0-9\ ]*)
            SCREEN_INFO="cannot read the screenshot"
            return 1
            ;;
    esac

    colors=${dims%% *}
    rest=${dims#* }
    width=${rest%% *}
    height=${rest#* }
    sd=$(convert "$png" -colorspace Gray -format '%[fx:standard_deviation]' info: 2>/dev/null)
    [ -n "$sd" ] || sd=0

    if [ "$width" -lt 400 ] || [ "$height" -lt 300 ]; then
        SCREEN_INFO="window is only ${width}x${height}"
        return 1
    fi

    if [ "$colors" -lt 12 ] || ! awk -v value="$sd" 'BEGIN { exit !(value + 0 >= 0.01) }'; then
        SCREEN_INFO="blank (${colors} colours, stddev $sd)"
        return 1
    fi

    SCREEN_INFO="${width}x${height}, ${colors} colours, stddev $sd"
    return 0
}

wait_rendered() { # name description seconds
    i=0
    while [ "$i" -lt "$3" ]; do
        if shot "$1" >/dev/null 2>&1 && screen_probe "$SHOTS/$1.png"; then
            echo "screenshot: $SHOTS/$1.png"
            pass "$2 ($SCREEN_INFO)"
            return 0
        fi
        i=$((i + 1))
        sleep 1
    done
    echo "screenshot: $SHOTS/$1.png"
    fail "$2: not rendered after $3 s (${SCREEN_INFO:-no screenshot})"
    return 1
}

wait_changed() { # name base-png minimum-pixels description seconds
    i=0
    metric=0
    while [ "$i" -lt "$5" ]; do
        if shot "$1" >/dev/null 2>&1; then
            metric=$(compare -metric AE "$2" "$SHOTS/$1.png" null: 2>&1 || true)
            case "$metric" in
                '' | *[!0-9]*) metric=0 ;;
            esac
            if [ "$metric" -ge "$3" ]; then
                echo "screenshot: $SHOTS/$1.png"
                pass "$4 ($metric pixels changed)"
                return 0
            fi
        fi
        i=$((i + 1))
        sleep 1
    done
    echo "screenshot: $SHOTS/$1.png"
    fail "$4 (only $metric pixels changed after $5 s, expected >= $3)"
    return 1
}

start_app() { # name [args...]
    name=$1
    shift
    "$APP_BIN" "$@" >"$LOGS/app-$name.log" 2>&1 &
    APP_PID=$!
    echo "$APP_PID" >"$T/app-$name.pid"
}

stop_app() { # wid
    [ -n "$APP_PID" ] || return 0
    python3 -I "$X11_CLOSE" "$1" >/dev/null 2>&1 || true
    if wait_for_process_exit "$APP_PID" 15; then
        wait "$APP_PID" 2>/dev/null
        code=$?
        if [ "$code" = 0 ]; then
            pass "the app exits cleanly (code 0) when the window is closed"
        else
            fail "the app exited with code $code after the close button"
        fi
    else
        fail "the app did not exit within 15 s of the close button"
        kill -9 "$APP_PID" 2>/dev/null
        wait "$APP_PID" 2>/dev/null
    fi
    APP_PID=""
}

# ---- build --------------------------------------------------------------------------------------

if [ "$SKIP_BUILD" != 1 ]; then
    echo "== build: cargo build -p lanomat-launcher-app"
    started=$(date +%s)
    if ! cargo build -p lanomat-launcher-app >"$LOGS/build.log" 2>&1; then
        tail_log "$LOGS/build.log"
        fatal "cargo build -p lanomat-launcher-app failed (see $LOGS/build.log)"
    fi
    echo "built in $(( $(date +%s) - started )) s: $APP_BIN"
fi
[ -x "$APP_BIN" ] || fatal "no launcher binary at $APP_BIN (build it, or set APP_BIN)"

# ---- 1. cold start: the connect screen, pairing ------------------------------------------------

echo "== 1. cold start: the connect screen renders and pairs"
start_app connect
APP_WID=$(wait_window 60) || APP_WID=""
if [ -z "$APP_WID" ]; then
    fail "window '$TITLE' did not appear within 60 s"
    tail_log "$LOGS/app-connect.log"
else
    pass "window '$TITLE' appeared ($APP_WID)"

    if xwininfo -id "$APP_WID" 2>/dev/null | grep -q 'Map State: IsViewable'; then
        pass "the window is mapped and visible"
    else
        fail "the window is not viewable (xwininfo)"
    fi

    if kill -0 "$APP_PID" 2>/dev/null; then
        pass "the shell process is alive after start"
    else
        fail "the shell process died during start"
        tail_log "$LOGS/app-connect.log"
    fi

    if wait_rendered 01-connect "the connect screen rendered" 30; then
        # Fixed window (960x720) and a fixed connect layout: the address field sits at (472,220).
        # The click is what gives the field DOM focus — the UI's own focus() runs before the
        # webview has window focus under Xvfb, and without a window manager nothing hands it over.
        submit_url() {
            xdotool mousemove --window "$APP_WID" 472 220 2>/dev/null || true
            xdotool click 1 2>/dev/null || true
            sleep 0.4
            xdotool key --clearmodifiers ctrl+a
            xdotool type --delay 30 "$BASE_URL"
            sleep 0.3
            xdotool key --clearmodifiers Return
        }

        sleep 1
        submit_url

        if ! log_wait "$STANDIN_LOG" "REQ POST /api/mcp/device" 12; then
            # Retry once through the field itself: the first click may have hit the boot skeleton
            # if WebKit painted its placeholder before the connect screen was shown.
            submit_url
        fi
    fi

    if expect_log_wait "$STANDIN_LOG" "REQ POST /api/mcp/device" 20 \
        "the connect form submitted the typed server address to the stub"; then
        expect_log "$STANDIN_LOG" 'body={"device_name":"LANoMAT Launcher on demo-pc"}' \
            "connect_start sent the documented device name"
    else
        shot 01-connect-failed >/dev/null 2>&1
        echo "      evidence: $SHOTS/01-connect-failed.png (the form stayed put instead of starting the device flow)"
    fi

    expect_log_wait "$STANDIN_LOG" "REQ POST /api/mcp/token" 30 \
        "the shell polls the device flow after the address was accepted"
    expect_log_wait "$STANDIN_LOG" "REQ GET /api/v1/me" 30 \
        "the shell reads /api/v1/me with the issued token"

    i=0
    while [ "$i" -lt 30 ]; do
        [ -s "$CONFIG_DIR/config.json" ] && break
        i=$((i + 1))
        sleep 0.5
    done
    if [ -s "$CONFIG_DIR/config.json" ]; then
        pass "pairing stored config.json in LANOMAT_LAUNCHER_CONFIG_DIR"
        expect_log "$CONFIG_DIR/config.json" "$BASE_URL" \
            "the stored config points at the stub server"
        expect_log "$CONFIG_DIR/config.json" "lmcp_smoke_issued" \
            "the stored config holds the issued token"
        mode=$(stat -c %a "$CONFIG_DIR/config.json")
        if [ "$mode" = 600 ]; then
            pass "config.json is mode 600"
        else
            fail "config.json is mode $mode (expected 600)"
        fi
    else
        fail "no config.json after pairing (see $LOGS/app-connect.log)"
    fi

    if expect_log_wait "$STANDIN_LOG" "REQ GET /api/v1/recipes" 25 \
        "the paired shell loads the library (GET /api/v1/recipes)"; then
        wait_rendered 02-library-paired "the library screen rendered after pairing" 30 &&
            wait_changed 02-library-paired "$SHOTS/01-connect.png" 20000 \
                "the screen changed from the connect form to the library" 15
    fi

    if grep -q 'panicked at' "$LOGS/app-connect.log"; then
        fail "the app panicked (see $LOGS/app-connect.log)"
    else
        pass "no panic in the app log"
    fi

    stop_app "$APP_WID"
fi

# ---- 2. seeded config: straight into the library with the stored token -------------------------

echo "== 2. seeded config: the library loads with the stored bearer token"
rm -rf "$CONFIG_DIR"
mkdir -p "$CONFIG_DIR"
chmod 700 "$CONFIG_DIR"
printf '%s' '{"version":1,"url":"'"$BASE_URL"'","token":"lmcp_smoke_seeded","token_expires_at":"2099-01-01T00:00:00Z","user":{"id":1,"name":"Testspieler"},"server_name":"Smoke LAN","server_version":"smoke"}' \
    >"$CONFIG_DIR/config.json"
chmod 600 "$CONFIG_DIR/config.json"

: >"$LOGS/app-library.log"
start_app library
APP_WID=$(wait_window 60) || APP_WID=""
if [ -z "$APP_WID" ]; then
    fail "window '$TITLE' did not appear within 60 s with a seeded config"
    tail_log "$LOGS/app-library.log"
else
    pass "window '$TITLE' appeared from the seeded config"

    if expect_log_wait "$STANDIN_LOG" "REQ GET /api/v1/recipes auth=Bearer lmcp_smoke_seeded" 30 \
        "the library request carried the stored bearer token"; then
        wait_rendered 03-library-seeded "the library screen rendered from the seeded config" 30 &&
            wait_changed 03-library-seeded "$SHOTS/01-connect.png" 20000 \
                "the seeded start shows the library, not the connect form" 15
    fi

    if [ "$(window_count)" = 1 ]; then
        pass "exactly one launcher window after the seeded start"
    else
        fail "unexpected window count after the seeded start: $(window_count)"
    fi

    if grep -q 'panicked at' "$LOGS/app-library.log"; then
        fail "the app panicked on the seeded start (see $LOGS/app-library.log)"
    else
        pass "no panic in the seeded-start log"
    fi
fi

# ---- 3. second instance: exit + deep-link handoff ----------------------------------------------

echo "== 3. a second instance hands its deep link to the first and exits"
if [ -z "$APP_WID" ]; then
    fail "no first instance to hand the deep link to"
else
    # A debug build exposes the WebKit inspector; the target list is a DOM-side cross-check.
    if python3 -I "$INSPECTOR" --port "$DEVPORT" --out "$SHOTS/devtools.html" \
        >"$LOGS/inspector.out" 2>&1; then
        if grep -qi "LANoMAT" "$SHOTS/devtools.html"; then
            pass "devtools listed the loaded document (see $SHOTS/devtools.html)"
        else
            skip "devtools answered, but its target list names no LANoMAT document"
        fi
    else
        skip "devtools inspector not reachable (WEBKIT_INSPECTOR_SERVER=$DEVPORT)"
    fi

    before=$(window_count)
    plans_before=$(awk '/POST \/api\/v1\/recipes\/unreal-tournament-2004\/plan/ { n++ } END { print n + 0 }' "$STANDIN_LOG")
    "$APP_BIN" "lanomat://install/unreal-tournament-2004" >"$LOGS/app-second.log" 2>&1 &
    SECOND_PID=$!

    if wait_for_process_exit "$SECOND_PID" 20; then
        wait "$SECOND_PID" 2>/dev/null
        code=$?
        SECOND_PID=""
        if [ "$code" = 0 ]; then
            pass "the second instance handed over and exited with code 0"
        else
            fail "the second instance exited with code $code (expected 0)"
            tail_log "$LOGS/app-second.log"
        fi
    else
        fail "the second instance did not exit within 20 s (single instance plugin)"
        tail_log "$LOGS/app-second.log"
        kill -9 "$SECOND_PID" 2>/dev/null
        wait "$SECOND_PID" 2>/dev/null
        SECOND_PID=""
    fi

    sleep 1
    after=$(window_count)
    if [ "$after" = "$before" ]; then
        pass "still $after launcher window (no second window was opened)"
    else
        fail "window count changed from $before to $after"
    fi

    if kill -0 "$APP_PID" 2>/dev/null; then
        pass "the first instance is still running"
    else
        fail "the first instance died during the deep-link handoff"
        tail_log "$LOGS/app-library.log"
    fi

    if expect_log_wait "$STANDIN_LOG" \
        "REQ POST /api/v1/recipes/unreal-tournament-2004/plan auth=Bearer lmcp_smoke_seeded" 30 \
        "the deep link opened the UT2004 card in the running instance (plan requested)"; then
        plans_after=$(awk '/POST \/api\/v1\/recipes\/unreal-tournament-2004\/plan/ { n++ } END { print n + 0 }' "$STANDIN_LOG")
        if [ "$plans_after" -gt "$plans_before" ]; then
            pass "the plan request is new ($plans_before -> $plans_after), not a leftover"
        else
            fail "the plan request count did not grow ($plans_before -> $plans_after)"
        fi
        wait_rendered 04-install "the install screen rendered after the deep link" 30 &&
            wait_changed 04-install "$SHOTS/03-library-seeded.png" 20000 \
                "the screen changed from the library to the install view" 15
    fi

    if grep -q 'panicked at' "$LOGS/app-library.log"; then
        fail "the app panicked after the deep link (see $LOGS/app-library.log)"
    else
        pass "no panic after the deep link"
    fi

    stop_app "$APP_WID"
fi

# ---- summary ------------------------------------------------------------------------------------

echo
echo "== summary: $PASSED passed, $FAILED failed"
echo "   screenshots: $SHOTS"
echo "   stub request log: $STANDIN_LOG"
echo "   app logs: $LOGS"

if [ "$FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
