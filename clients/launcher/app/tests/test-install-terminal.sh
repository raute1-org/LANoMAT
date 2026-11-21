#!/bin/sh
# Test for scripts/install-terminal.sh (the gaming-terminal / arcade-cabinet
# spin-up installer).
#
#   sh clients/launcher/app/tests/test-install-terminal.sh
#
# Everything happens in a fresh temp directory that stands in for $HOME, so the
# real user's ~/.local and ~/.config are never touched. systemctl is a stub that
# only records its calls; a case also runs with a PATH that has no systemctl.
#
# Asserted:
#   * syntax (sh -n and bash -n)
#   * a bare binary installs to ~/.local/bin with the desktop entry, the icon
#     and an autostart entry (which starts the installed binary)
#   * --arcade adds --kiosk to the installed entry and to the autostart entry,
#     exactly once on a second run (byte-identical files)
#   * --name puts LANOMAT_TERMINAL_NAME into the autostart command
#   * --no-autostart leaves no autostart entry and removes an older one
#   * --autostart=systemd writes a user unit and enables it through systemctl
#     (and still works when systemctl is missing)
#   * a tarball is unpacked, installed, and its temp directory is removed
#   * --uninstall removes every added file, twice in a row is fine
set -u

HERE=$(cd "$(dirname "$0")" && pwd)
LAUNCHER=$(dirname "$(dirname "$HERE")")
INSTALL=$LAUNCHER/scripts/install-terminal.sh
DESKTOP_INSTALL=$LAUNCHER/scripts/install-desktop.sh
ICON_SRC=$LAUNCHER/app/icons/icon.png

TMP=$(mktemp -d "${TMPDIR:-/tmp}/lanomat-install-terminal.XXXXXX") || exit 2
LOG=$TMP/install.log
PASSED=0
FAILED=0

pass() { PASSED=$((PASSED + 1)); printf 'PASS  %s\n' "$*"; }
fail() { FAILED=$((FAILED + 1)); printf 'FAIL  %s\n' "$*"; }
skip() { printf 'SKIP  %s\n' "$*"; }

# shellcheck disable=SC2329 # invoked through the EXIT/INT/TERM trap
cleanup() { rm -rf "$TMP"; }
trap cleanup 0 1 2 15

fatal() {
    printf 'FATAL %s\n' "$*"
    exit 2
}

[ -f "$INSTALL" ] || fatal "missing installer: $INSTALL"
[ -f "$DESKTOP_INSTALL" ] || fatal "missing install-desktop.sh: $DESKTOP_INSTALL"
[ -f "$ICON_SRC" ] || fatal "missing icon fixture: $ICON_SRC"

# ---- helpers -------------------------------------------------------------------------------

fake_binary() { # <path>
    mkdir -p "$(dirname "$1")"
    printf '#!/bin/sh\nexit 0\n' >"$1"
    chmod 0755 "$1"
}

# shellcheck disable=SC2329 # invoked indirectly: expect_run "$desc" run_script ...
run_script() { # <script> <home> [args...]
    run_script_script=$1
    run_script_home=$2
    shift 2
    env -u LANOMAT_TERMINAL_NAME \
        TMPDIR="$TMP" \
        HOME="$run_script_home" \
        XDG_DATA_HOME="$run_script_home/.local/share" \
        XDG_CONFIG_HOME="$run_script_home/.config" \
        LANOMAT_BIN_DIR="$run_script_home/.local/bin" \
        sh "$run_script_script" "$@"
}

# shellcheck disable=SC2329 # invoked indirectly
run_script_with_path() { # <script> <home> <path> [args...]
    run_path_script=$1
    run_path_home=$2
    run_path_path=$3
    shift 3
    env -u LANOMAT_TERMINAL_NAME \
        TMPDIR="$TMP" \
        PATH="$run_path_path" \
        HOME="$run_path_home" \
        XDG_DATA_HOME="$run_path_home/.local/share" \
        XDG_CONFIG_HOME="$run_path_home/.config" \
        LANOMAT_BIN_DIR="$run_path_home/.local/bin" \
        sh "$run_path_script" "$@"
}

expect_run() { # <description> <command...>
    expect_desc=$1
    shift
    if "$@" >>"$LOG" 2>&1; then
        pass "$expect_desc"
        return 0
    fi
    expect_code=$?
    fail "$expect_desc (exit $expect_code)"
    printf -- '--- last lines of %s\n' "$LOG"
    tail -n 20 "$LOG"
    return 1
}

expect_file() { # <path> <description>
    if [ -f "$1" ]; then pass "$2"; else fail "$2 (missing: $1)"; fi
}

expect_gone() { # <path> <description>
    if [ ! -e "$1" ]; then pass "$2"; else fail "$2 (still there: $1)"; fi
}

expect_executable() { # <path> <description>
    if [ -x "$1" ]; then pass "$2"; else fail "$2 (not executable: $1)"; fi
}

expect_line() { # <file> <exact line> <description>
    if grep -qxF -- "$2" "$1" 2>/dev/null; then
        pass "$3"
    else
        fail "$3 (no line '$2' in $1)"
    fi
}

expect_grep() { # <file> <pattern> <description>
    if grep -q -- "$2" "$1" 2>/dev/null; then
        pass "$3"
    else
        fail "$3 (no '$2' in $1)"
    fi
}

expect_no_grep() { # <file> <pattern> <description>
    if grep -q -- "$2" "$1" 2>/dev/null; then
        fail "$3 (found '$2' in $1)"
    else
        pass "$3"
    fi
}

expect_count() { # <file> <pattern> <expected> <description>
    count=$(grep -c -- "$2" "$1" 2>/dev/null || true)
    if [ "$count" = "$3" ]; then
        pass "$4"
    else
        fail "$4 (found $count of '$2', expected $3)"
    fi
}

expect_same() { # <a> <b> <description>
    if [ "$1" = "$2" ]; then pass "$3"; else fail "$3 ('$1' != '$2')"; fi
}

expect_no_temp_dirs() { # <dir> <description>
    leftovers=$(find "$1" -maxdepth 1 -type d -name 'lanomat-terminal.*' 2>/dev/null || true)
    if [ -z "$leftovers" ]; then
        pass "$2"
    else
        fail "$2 (left over: $leftovers)"
    fi
}

# ---- 0. syntax ------------------------------------------------------------------------------

echo
echo "== 0. the script is valid sh and bash"

if sh -n "$INSTALL" 2>"$TMP/sh-n.log"; then
    pass "sh -n accepts install-terminal.sh"
else
    fail "sh -n rejects install-terminal.sh"
    cat "$TMP/sh-n.log"
fi

if command -v bash >/dev/null 2>&1; then
    if bash -n "$INSTALL" 2>"$TMP/bash-n.log"; then
        pass "bash -n accepts install-terminal.sh"
    else
        fail "bash -n rejects install-terminal.sh"
        cat "$TMP/bash-n.log"
    fi
else
    skip "bash not installed"
fi

# ---- 1. a plain terminal install ------------------------------------------------------------

echo
echo "== 1. a bare binary installs with a desktop entry and an autostart entry"

HOME_DIR=$TMP/home
mkdir -p "$HOME_DIR"
fake_binary "$TMP/package/lanomat-launcher"

expect_run "the installer runs" run_script "$INSTALL" "$HOME_DIR" \
    --bin "$TMP/package/lanomat-launcher"

BIN_FILE=$HOME_DIR/.local/bin/lanomat-launcher
DESKTOP_FILE=$HOME_DIR/.local/share/applications/lanomat-launcher.desktop
ICON_FILE=$HOME_DIR/.local/share/icons/hicolor/256x256/apps/lanomat-launcher.png
AUTOSTART_FILE=$HOME_DIR/.config/autostart/lanomat-launcher.desktop

expect_file "$BIN_FILE" "the binary is in ~/.local/bin"
expect_executable "$BIN_FILE" "the installed binary is executable"
expect_file "$DESKTOP_FILE" "the desktop entry is in \$XDG_DATA_HOME/applications"
expect_file "$ICON_FILE" "the icon is in hicolor/256x256/apps"
expect_file "$AUTOSTART_FILE" "the autostart entry is in \$XDG_CONFIG_HOME/autostart"

expect_line "$DESKTOP_FILE" "Exec=$BIN_FILE %u" "the entry starts the installed binary"
expect_line "$AUTOSTART_FILE" "Exec=$BIN_FILE" "the autostart entry starts the installed binary"
expect_line "$AUTOSTART_FILE" "X-LANoMAT-Terminal=true" "the autostart entry is marked as a terminal"
expect_no_grep "$DESKTOP_FILE" '--kiosk' "a plain install is not fullscreen"
expect_no_grep "$AUTOSTART_FILE" 'LANOMAT_TERMINAL_NAME' "without --name the autostart keeps the hostname"

# ---- 2. --arcade: fullscreen and idempotent -------------------------------------------------

echo
echo "== 2. --arcade installs a fullscreen terminal, twice with the same bytes"

expect_run "--arcade runs" run_script "$INSTALL" "$HOME_DIR" --arcade \
    --bin "$TMP/package/lanomat-launcher"

expect_line "$DESKTOP_FILE" "Exec=$BIN_FILE --arcade %u" "the desktop entry starts fullscreen"
expect_line "$AUTOSTART_FILE" "Exec=$BIN_FILE --arcade" "the autostart entry starts fullscreen"
expect_count "$DESKTOP_FILE" '--arcade' 1 "the desktop entry carries the flag exactly once"
expect_count "$AUTOSTART_FILE" '--arcade' 1 "the autostart entry carries the flag exactly once"
expect_no_grep "$DESKTOP_FILE" '--kiosk' "--arcade does not lock the window"

FIRST_SUM=$(cksum "$DESKTOP_FILE" "$AUTOSTART_FILE")
expect_run "--arcade runs again" run_script "$INSTALL" "$HOME_DIR" --arcade \
    --bin "$TMP/package/lanomat-launcher"
expect_same "$FIRST_SUM" "$(cksum "$DESKTOP_FILE" "$AUTOSTART_FILE")" \
    "a second --arcade run changes nothing"

# ---- 2b. --kiosk: the locked variant ---------------------------------------------------------

echo
echo "== 2b. --kiosk locks the fullscreen (and is also added exactly once)"

KIOSK_HOME=$TMP/home-kiosk
mkdir -p "$KIOSK_HOME"
expect_run "--kiosk runs" run_script "$INSTALL" "$KIOSK_HOME" --kiosk \
    --bin "$TMP/package/lanomat-launcher"

expect_line "$KIOSK_HOME/.local/share/applications/lanomat-launcher.desktop" \
    "Exec=$KIOSK_HOME/.local/bin/lanomat-launcher --kiosk %u" \
    "the desktop entry is a locked kiosk"
expect_line "$KIOSK_HOME/.config/autostart/lanomat-launcher.desktop" \
    "Exec=$KIOSK_HOME/.local/bin/lanomat-launcher --kiosk" \
    "the autostart entry is a locked kiosk"
expect_count "$KIOSK_HOME/.local/share/applications/lanomat-launcher.desktop" '--kiosk' 1 \
    "the kiosk flag is not duplicated"

# ---- 3. --name --------------------------------------------------------------------------------

echo
echo "== 3. --name names the cabinet in the autostart command"

expect_run "--arcade --name runs" run_script "$INSTALL" "$HOME_DIR" --arcade \
    --name "Arcade 3" --bin "$TMP/package/lanomat-launcher"

expect_line "$AUTOSTART_FILE" "Exec=env LANOMAT_TERMINAL_NAME=\"Arcade 3\" $BIN_FILE --arcade" \
    "the autostart entry exports LANOMAT_TERMINAL_NAME"
expect_count "$AUTOSTART_FILE" 'LANOMAT_TERMINAL_NAME' 1 "the name is exported once"

if run_script "$INSTALL" "$HOME_DIR" --name "Arcade; rm -rf /" \
    --bin "$TMP/package/lanomat-launcher" >>"$LOG" 2>&1; then
    fail "an unsafe --name is rejected"
else
    pass "an unsafe --name is rejected"
fi

# ---- 4. --no-autostart ------------------------------------------------------------------------

echo
echo "== 4. --no-autostart leaves no autostart entry"

expect_run "--no-autostart runs" run_script "$INSTALL" "$HOME_DIR" --no-autostart \
    --bin "$TMP/package/lanomat-launcher"

expect_gone "$AUTOSTART_FILE" "--no-autostart removed the older autostart entry"
expect_file "$DESKTOP_FILE" "the desktop entry is still installed"

# ---- 5. systemd user unit ---------------------------------------------------------------------

echo
echo "== 5. --autostart=systemd writes and enables a user unit"

STUB=$TMP/stub-bin
mkdir -p "$STUB"
cat >"$STUB/systemctl" <<STUBSCRIPT
#!/bin/sh
printf '%s\n' "systemctl \$*" >>"$TMP/systemctl.log"
exit 0
STUBSCRIPT
chmod 0755 "$STUB/systemctl"

UNIT_FILE=$HOME_DIR/.config/systemd/user/lanomat-launcher.service
REAL_PATH=$PATH

expect_run "systemd autostart runs" run_script_with_path "$INSTALL" "$HOME_DIR" "$STUB:$REAL_PATH" \
    --arcade --autostart=systemd --name "Cab 1" --bin "$TMP/package/lanomat-launcher"

expect_file "$UNIT_FILE" "the user unit is written"
expect_gone "$AUTOSTART_FILE" "switching to systemd removed the autostart entry"
expect_grep "$UNIT_FILE" "^ExecStart=$BIN_FILE --arcade$" "the unit starts the binary fullscreen"
expect_grep "$UNIT_FILE" '^Environment="LANOMAT_TERMINAL_NAME=Cab 1"$' "the unit carries the terminal name"
expect_line "$UNIT_FILE" "WantedBy=graphical-session.target" "the unit belongs to the graphical session"
expect_grep "$TMP/systemctl.log" "systemctl --user enable $UNIT_FILE" \
    "the unit is enabled for the user session by path"
expect_count "$UNIT_FILE" 'LANOMAT_TERMINAL_NAME' 1 "the name appears once in the unit"

# ---- 6. no systemctl on PATH ------------------------------------------------------------------

echo
echo "== 6. a machine without systemctl still installs a unit"

WRAP=$TMP/no-systemctl-bin
mkdir -p "$WRAP"
for tool in sh sed awk grep cp mv rm mkdir chmod cat dirname basename find head tar mktemp hostname sleep env; do
    tool_path=$(command -v "$tool" 2>/dev/null) || continue
    ln -sf "$tool_path" "$WRAP/$tool"
done
if [ ! -x "$WRAP/sh" ] || [ ! -x "$WRAP/sed" ] || [ ! -x "$WRAP/find" ]; then
    skip "cannot build a reduced PATH"
else
    BARE_HOME=$TMP/home-bare
    mkdir -p "$BARE_HOME"
    if run_script_with_path "$INSTALL" "$BARE_HOME" "$WRAP" --autostart=systemd \
        --bin "$TMP/package/lanomat-launcher" >>"$LOG" 2>&1; then
        pass "the installer succeeds without systemctl"
    else
        fail "the installer failed without systemctl"
        tail -n 20 "$LOG"
    fi
    expect_file "$BARE_HOME/.config/systemd/user/lanomat-launcher.service" \
        "the unit is written without systemctl"
fi

# ---- 7. a tarball source ----------------------------------------------------------------------

echo
echo "== 7. a launcher tarball is unpacked, installed and cleaned up"

PKG=$TMP/tarball/lanomat-launcher-test-linux-x86_64
mkdir -p "$PKG"
fake_binary "$PKG/lanomat-launcher"
cp "$DESKTOP_INSTALL" "$PKG/install-desktop.sh"
cp "$ICON_SRC" "$PKG/lanomat-launcher.png"
TARBALL=$TMP/lanomat-launcher-test-linux-x86_64.tar.gz
if tar -czf "$TARBALL" -C "$TMP/tarball" lanomat-launcher-test-linux-x86_64 >>"$LOG" 2>&1; then
    pass "the fixture tarball is built"
else
    fail "could not build the fixture tarball"
fi

TARBALL_HOME=$TMP/home-tarball
mkdir -p "$TARBALL_HOME"
expect_run "the installer runs from the tarball" run_script "$INSTALL" "$TARBALL_HOME" \
    --arcade "$TARBALL"

expect_file "$TARBALL_HOME/.local/bin/lanomat-launcher" "the binary from the tarball is installed"
expect_executable "$TARBALL_HOME/.local/bin/lanomat-launcher" "the tarball binary is executable"
expect_line "$TARBALL_HOME/.config/autostart/lanomat-launcher.desktop" \
    "Exec=$TARBALL_HOME/.local/bin/lanomat-launcher --arcade" \
    "the tarball install starts fullscreen"
expect_no_temp_dirs "$TMP" "the unpacked tarball directory is removed"

# ---- 8. uninstall -----------------------------------------------------------------------------

echo
echo "== 8. --uninstall removes every added file and is safe to repeat"

expect_run "--uninstall runs" run_script_with_path "$INSTALL" "$HOME_DIR" "$STUB:$REAL_PATH" --uninstall

expect_gone "$AUTOSTART_FILE" "the autostart entry is gone"
expect_gone "$UNIT_FILE" "the user unit is gone"
expect_gone "$DESKTOP_FILE" "the desktop entry is gone"
expect_gone "$ICON_FILE" "the icon is gone"
expect_gone "$BIN_FILE" "the binary is gone"
expect_grep "$TMP/systemctl.log" "systemctl --user disable $UNIT_FILE" \
    "the unit is disabled again"

expect_run "--uninstall is safe to repeat" run_script_with_path "$INSTALL" "$HOME_DIR" \
    "$STUB:$REAL_PATH" --uninstall

if [ -d "$HOME_DIR/.local/bin" ]; then
    pass "the bin folder itself is kept"
else
    fail "the bin folder was removed"
fi

# ---- summary ----------------------------------------------------------------------------------

echo
echo "== summary: $PASSED passed, $FAILED failed"
echo "   temp dir: $TMP (removed on exit)"
if [ "$FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
