#!/bin/sh
# Test for scripts/install-desktop.sh (the installer that makes lanomat:// links work after
# unpacking the tarball).
#
#   sh clients/launcher/app/tests/test-install-desktop.sh
#
# Everything happens in a fresh temp directory that stands in for $HOME, so the real user's
# ~/.local is never touched. xdg-mime and update-desktop-database may be installed or not; the
# installer treats both as best effort, and one case runs with a PATH that has neither.
#
# Asserted:
#   * the desktop entry lands in $XDG_DATA_HOME/applications with an absolute Exec= that points
#     at the installed binary, the x-scheme-handler/lanomat MimeType and Icon=lanomat-launcher
#   * the binary and the icon land where the entry says, and the icon is byte-identical
#   * re-running changes nothing and leaves no temp files behind
#   * a $HOME with spaces produces a quoted Exec= (Desktop Entry spec quoting)
#   * the tarball layout (script + .desktop + .png side by side) is used as the template, so
#     extra keys survive and Exec= is still rewritten
#   * --uninstall removes exactly those files, twice in a row is fine
#   * both packaging scripts build the entry and the readme from clients/launcher/scripts
set -u

HERE=$(cd "$(dirname "$0")" && pwd)
LAUNCHER=$(dirname "$(dirname "$HERE")")
REPO=$(dirname "$(dirname "$LAUNCHER")")
INSTALL=$LAUNCHER/scripts/install-desktop.sh
ICON_SRC=$LAUNCHER/app/icons/icon.png

TMP=$(mktemp -d "${TMPDIR:-/tmp}/lanomat-install-desktop.XXXXXX") || exit 2
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
    env HOME="$run_script_home" \
        XDG_DATA_HOME="$run_script_home/.local/share" \
        XDG_CONFIG_HOME="$run_script_home/.config" \
        LANOMAT_BIN_DIR="$run_script_home/.local/bin" \
        sh "$run_script_script" "$@"
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

expect_no_temp_files() { # <dir> <description>
    leftovers=""
    for entry in "$1"/* "$1"/.[!.]*; do
        [ -e "$entry" ] || continue
        case ${entry##*/} in
            *new.* | *tmp*) leftovers=$leftovers" ${entry##*/}" ;;
        esac
    done
    if [ -n "$leftovers" ]; then
        fail "$2 (left over:$leftovers)"
    else
        pass "$2"
    fi
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

expect_same_bytes() { # <file-a> <file-b> <description>
    if cmp -s "$1" "$2"; then pass "$3"; else fail "$3 ($1 and $2 differ)"; fi
}

# ---- 1. a plain install ---------------------------------------------------------------------

echo
echo "== 1. install from a folder with the binary (no .desktop, no icon beside it)"

HOME_DIR=$TMP/home
mkdir -p "$HOME_DIR"
fake_binary "$TMP/package/lanomat-launcher"

expect_run "the installer runs" run_script "$INSTALL" "$HOME_DIR" --bin "$TMP/package/lanomat-launcher"

APP_DIR=$HOME_DIR/.local/share/applications
ICON_DIR=$HOME_DIR/.local/share/icons/hicolor/256x256/apps
DESKTOP_FILE=$APP_DIR/lanomat-launcher.desktop
BIN_FILE=$HOME_DIR/.local/bin/lanomat-launcher

expect_file "$DESKTOP_FILE" "the desktop entry is in \$XDG_DATA_HOME/applications"
expect_file "$BIN_FILE" "the binary is copied to ~/.local/bin"
expect_executable "$BIN_FILE" "the installed binary is executable"
expect_file "$ICON_DIR/lanomat-launcher.png" "the icon is in hicolor/256x256/apps"

expect_line "$DESKTOP_FILE" "Exec=$BIN_FILE %u" "Exec= is the absolute binary path"
expect_line "$DESKTOP_FILE" "MimeType=x-scheme-handler/lanomat;" "the entry claims the lanomat scheme"
expect_line "$DESKTOP_FILE" "Icon=lanomat-launcher" "the entry names the installed icon"
expect_line "$DESKTOP_FILE" "Type=Application" "the entry is an application"
expect_line "$DESKTOP_FILE" "Terminal=false" "the entry starts without a terminal"
expect_grep "$DESKTOP_FILE" '^\[Desktop Entry\]$' "the entry has its group header"

if [ -f "$ICON_DIR/lanomat-launcher.png" ]; then
    expect_same_bytes "$ICON_SRC" "$ICON_DIR/lanomat-launcher.png" \
        "the icon is copied unchanged"
fi

if command -v desktop-file-validate >/dev/null 2>&1; then
    if desktop-file-validate "$DESKTOP_FILE" >"$TMP/validate.log" 2>&1; then
        pass "desktop-file-validate accepts the entry"
    else
        fail "desktop-file-validate rejects the entry"
        cat "$TMP/validate.log"
    fi
else
    skip "desktop-file-validate not installed"
fi

if command -v xdg-mime >/dev/null 2>&1; then
    handler=$(XDG_DATA_HOME="$HOME_DIR/.local/share" XDG_CONFIG_HOME="$HOME_DIR/.config" \
        xdg-mime query default x-scheme-handler/lanomat 2>/dev/null || true)
    if [ "$handler" = "lanomat-launcher.desktop" ]; then
        pass "xdg-mime reports the launcher as the lanomat:// handler"
    else
        fail "xdg-mime reports '$handler' as the lanomat:// handler"
    fi
else
    skip "xdg-mime not installed"
fi

# ---- 2. re-running is harmless --------------------------------------------------------------

echo
echo "== 2. a second run changes nothing and leaves no temp files"

first_sum=$(cksum "$DESKTOP_FILE")
expect_run "the installer runs again" run_script "$INSTALL" "$HOME_DIR" --bin "$TMP/package/lanomat-launcher"
second_sum=$(cksum "$DESKTOP_FILE")
if [ "$first_sum" = "$second_sum" ]; then
    pass "the desktop entry is byte-identical after a second run"
else
    fail "the desktop entry changed on a second run"
fi
expect_no_temp_files "$APP_DIR" "no temporary files are left in \$XDG_DATA_HOME/applications"
expect_no_temp_files "$HOME_DIR/.local/bin" "no temporary files are left in ~/.local/bin"

# ---- 3. $HOME with spaces -------------------------------------------------------------------

echo
echo "== 3. a path with spaces gets a quoted Exec= (Desktop Entry spec)"

SPACED_HOME="$TMP/home with space"
mkdir -p "$SPACED_HOME"
expect_run "the installer runs with spaces in \$HOME" run_script "$INSTALL" "$SPACED_HOME" \
    --bin "$TMP/package/lanomat-launcher"

SPACED_BIN="$SPACED_HOME/.local/bin/lanomat-launcher"
expect_file "$SPACED_BIN" "the binary lands under the spaced home"
expect_line "$SPACED_HOME/.local/share/applications/lanomat-launcher.desktop" \
    "Exec=\"$SPACED_BIN\" %u" "Exec= is quoted when the path has spaces"

# ---- 4. the tarball layout ------------------------------------------------------------------

echo
echo "== 4. the tarball layout (script, .desktop and .png next to each other) is the template"

PKG=$TMP/tarball/lanomat-launcher-test-linux-x86_64
mkdir -p "$PKG"
cp "$INSTALL" "$PKG/install-desktop.sh"
fake_binary "$PKG/lanomat-launcher"
cp "$ICON_SRC" "$PKG/lanomat-launcher.png"

if sh "$INSTALL" --print-desktop >"$PKG/lanomat-launcher.desktop" 2>"$TMP/print.log"; then
    pass "--print-desktop renders the packaged entry"
else
    fail "--print-desktop failed"
    cat "$TMP/print.log"
fi
expect_line "$PKG/lanomat-launcher.desktop" "Exec=lanomat-launcher %u" \
    "the packaged entry keeps the bare Exec= for a PATH install"
expect_line "$PKG/lanomat-launcher.desktop" "MimeType=x-scheme-handler/lanomat;" \
    "the packaged entry claims the lanomat scheme"
expect_line "$PKG/lanomat-launcher.desktop" "StartupWMClass=lanomat-launcher" \
    "the packaged entry names the window class"
printf 'X-LANoMAT-Marker=kept\n' >>"$PKG/lanomat-launcher.desktop"

TARBALL_HOME="$TMP/home-tarball"
mkdir -p "$TARBALL_HOME"
expect_run "the installer runs from the unpacked folder" run_script "$PKG/install-desktop.sh" \
    "$TARBALL_HOME"
TARBALL_DESKTOP=$TARBALL_HOME/.local/share/applications/lanomat-launcher.desktop
expect_line "$TARBALL_DESKTOP" "Exec=$TARBALL_HOME/.local/bin/lanomat-launcher %u" \
    "the packaged entry's Exec= becomes absolute"
expect_line "$TARBALL_DESKTOP" "X-LANoMAT-Marker=kept" \
    "keys from the packaged entry survive the rewrite"
expect_file "$TARBALL_HOME/.local/share/icons/hicolor/256x256/apps/lanomat-launcher.png" \
    "the icon next to the script is installed"

# ---- 5. without xdg-mime and update-desktop-database ----------------------------------------

echo
echo "== 5. xdg-mime and update-desktop-database are optional"

WRAP=$TMP/no-xdg-bin
mkdir -p "$WRAP"
for tool in sh sed awk grep cp mv rm mkdir chmod cat dirname basename cmp ls; do
    tool_path=$(command -v "$tool" 2>/dev/null) || continue
    ln -s "$tool_path" "$WRAP/$tool"
done
if [ ! -x "$WRAP/sh" ] || [ ! -x "$WRAP/sed" ]; then
    skip "cannot build a reduced PATH (sh/sed missing)"
else
    BARE_HOME=$TMP/home-bare
    mkdir -p "$BARE_HOME"
    if env PATH="$WRAP" HOME="$BARE_HOME" \
        XDG_DATA_HOME="$BARE_HOME/.local/share" XDG_CONFIG_HOME="$BARE_HOME/.config" \
        LANOMAT_BIN_DIR="$BARE_HOME/.local/bin" \
        sh "$INSTALL" --bin "$TMP/package/lanomat-launcher" >>"$LOG" 2>&1; then
        pass "the installer succeeds without xdg-mime/update-desktop-database"
    else
        fail "the installer failed without xdg-mime/update-desktop-database"
        tail -n 20 "$LOG"
    fi
    expect_line "$BARE_HOME/.local/share/applications/lanomat-launcher.desktop" \
        "Exec=$BARE_HOME/.local/bin/lanomat-launcher %u" \
        "the entry is still written without xdg-mime"
fi

# ---- 6. uninstall ---------------------------------------------------------------------------

echo
echo "== 6. --uninstall removes exactly the three files"

expect_run "--uninstall runs" run_script "$INSTALL" "$HOME_DIR" --uninstall
expect_gone "$DESKTOP_FILE" "the desktop entry is gone"
expect_gone "$BIN_FILE" "the binary is gone"
expect_gone "$ICON_DIR/lanomat-launcher.png" "the icon is gone"
expect_run "--uninstall is safe to repeat" run_script "$INSTALL" "$HOME_DIR" --uninstall
if [ -d "$HOME_DIR/.local/share/applications" ]; then
    pass "the applications folder itself is kept"
else
    fail "the applications folder was removed"
fi

# ---- 7. the packaging scripts share these files ---------------------------------------------

echo
echo "== 7. packaging scripts use scripts/install-desktop.sh and scripts/liesmich.txt"

OPS=$REPO/scripts/ops/build-launcher.sh
CI=$LAUNCHER/ci/build.sh
for script in "$OPS" "$CI"; do
    name=${script#"$REPO"/}
    if [ -f "$script" ]; then
        expect_grep "$script" 'install-desktop.sh.*--print-desktop' \
            "$name renders the desktop entry from the installer"
        expect_grep "$script" 'scripts/install-desktop.sh' \
            "$name ships the installer in the package"
        expect_grep "$script" 'scripts/liesmich.txt' \
            "$name fills in the shared readme"
    else
        skip "not in this checkout: $name"
    fi
done
for script in "$OPS" "$CI"; do
    [ -f "$script" ] || continue
    if grep -q '^cat > .*LIESMICH.txt' "$script"; then
        fail "${script#"$REPO"/} still writes its own LIESMICH (the two packagers would drift)"
    else
        pass "${script#"$REPO"/} has no private LIESMICH copy"
    fi
done

# ---- summary --------------------------------------------------------------------------------

echo
echo "== summary: $PASSED passed, $FAILED failed"
echo "   temp dir: $TMP (removed on exit)"
if [ "$FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
