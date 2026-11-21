#!/bin/sh
# Install the LANoMAT Launcher for the current user on Linux, so that
# lanomat://install/<slug> and lanomat://launch/<slug> open it.
#
#   ./install-desktop.sh                  # install from the folder this script is in
#   ./install-desktop.sh --uninstall      # remove exactly those files again
#   ./install-desktop.sh --print-desktop  # print the desktop entry the tarball ships
#
# What it does (no root, no network, safe to re-run):
#   * copies `lanomat-launcher` to ~/.local/bin ($LANOMAT_BIN_DIR),
#   * writes ~/.local/share/applications/lanomat-launcher.desktop whose Exec= points at the
#     absolute binary path (quoted when the path has spaces),
#   * copies the icon to ~/.local/share/icons/hicolor/256x256/apps/lanomat-launcher.png,
#   * runs update-desktop-database and makes the entry the handler for x-scheme-handler/lanomat.
#
# Why this exists: the Tauri deep-link plugin registers URL schemes itself only for AppImage
# builds. A plain binary unpacked from the tarball has no desktop entry, so a lanomat:// link
# from the website would go nowhere. XDG_DATA_HOME is honoured; --uninstall removes only the
# three files above and leaves the rest of the menu alone.
#
# The packaging scripts render the shipped entry with `--print-desktop`, so the tarball and
# this installer cannot drift apart.
set -eu

PROG=${0##*/}
APP=lanomat-launcher
SCHEME=lanomat

UNINSTALL=0
PRINT_DESKTOP=0
REGISTER=1
GIVEN_BIN=""

die() { printf '%s: %s\n' "$PROG" "$*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }
warn() { printf '%s: %s\n' "$PROG" "$*" >&2; }

usage() {
    cat <<'USAGE'
Usage: install-desktop.sh [options]

  --bin PATH         use this lanomat-launcher instead of the one next to the script
  --uninstall        remove the installed binary, desktop entry and icon again
  --no-register      write the files but do not touch the lanomat:// handler
  --print-desktop    print the desktop entry that ships in the tarball
  -h, --help         show this help

Directories: ~/.local/bin, $XDG_DATA_HOME/applications and
$XDG_DATA_HOME/icons/hicolor/256x256/apps (default $HOME/.local/share).
USAGE
}

# The entry exactly as it ships in the tarball. Exec uses the bare name here because the
# installer rewrites it to the absolute path; a manual install puts the binary in ~/.local/bin,
# so the shipped entry works when that directory is on PATH.
print_desktop() {
    cat <<'ENTRY'
[Desktop Entry]
Type=Application
Name=LANoMAT Launcher
Comment=Spiele aus der LAN mit einem Klick installieren und starten
Exec=lanomat-launcher %u
Icon=lanomat-launcher
Terminal=false
Categories=Game;
MimeType=x-scheme-handler/lanomat;
StartupWMClass=lanomat-launcher
ENTRY
}

while [ $# -gt 0 ]; do
    case $1 in
        --uninstall) UNINSTALL=1 ;;
        --print-desktop) PRINT_DESKTOP=1 ;;
        --bin)
            [ $# -ge 2 ] || die "--bin needs a path"
            GIVEN_BIN=$2
            shift
            ;;
        --no-register) REGISTER=0 ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            usage >&2
            die "unknown argument: $1"
            ;;
    esac
    shift
done

if [ "$PRINT_DESKTOP" = 1 ]; then
    print_desktop
    exit 0
fi

[ -n "${HOME:-}" ] || die "HOME is not set"
[ -d "$HOME" ] || die "HOME does not exist: $HOME"

DATA_HOME=${XDG_DATA_HOME:-$HOME/.local/share}
# The XDG basedir spec ignores a relative XDG_DATA_HOME.
case $DATA_HOME in
    /*) ;;
    *) DATA_HOME=$HOME/.local/share ;;
esac
BIN_DIR=${LANOMAT_BIN_DIR:-$HOME/.local/bin}
APP_DIR=$DATA_HOME/applications
ICON_DIR=$DATA_HOME/icons/hicolor/256x256/apps
DESKTOP_FILE=$APP_DIR/$APP.desktop
ICON_FILE=$ICON_DIR/$APP.png
BIN_FILE=$BIN_DIR/$APP

case $0 in
    */*) OUR_DIR=$(cd "${0%/*}" && pwd) ;;
    *) OUR_DIR=$(pwd) ;;
esac

TMP_BIN=""
TMP_DESKTOP=""
cleanup() {
    [ -z "$TMP_BIN" ] || rm -f "$TMP_BIN"
    [ -z "$TMP_DESKTOP" ] || rm -f "$TMP_DESKTOP"
}
trap cleanup 0 1 2 15

abspath() { # <existing path>
    case $1 in
        /*) printf '%s' "$1" ;;
        *) printf '%s/%s' "$(cd "$(dirname "$1")" && pwd)" "$(basename "$1")" ;;
    esac
}

# The value of the Exec= key for one absolute path: `%` is doubled (it is a field code) and
# the argument is double-quoted, with the four reserved characters escaped, when it needs it.
desktop_exec_value() {
    value=$1
    value=$(printf '%s' "$value" | sed -e 's/%/%%/g')
    case $value in
        *[[:space:]]* | *'"'* | *'$'* | *\\* | *\`*)
            # shellcheck disable=SC2016 # the sed script is literal: backtick, $ and \ are escaped
            value=$(printf '%s' "$value" |
                sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/`/\\`/g' -e 's/\$/\\$/g')
            printf '"%s"' "$value"
            ;;
        *) printf '%s' "$value" ;;
    esac
}

# Replace every line that starts with <key>=, keep the position, append it when it is missing.
rewrite_key() { # <file> <key> <line>
    rewrite_key_file=$1
    rewrite_key_name=$2
    rewrite_key_line=$3
    awk -v key="$rewrite_key_name=" -v line="$rewrite_key_line" '
        index($0, key) == 1 {
            if (!replaced) {
                print line
                replaced = 1
            }
            next
        }
        { print }
        END { if (!replaced) print line }
    ' "$rewrite_key_file" >"$rewrite_key_file.new.$$" ||
        die "cannot rewrite $rewrite_key_file"
    mv -f "$rewrite_key_file.new.$$" "$rewrite_key_file"
}

ensure_key() { # <file> <key> <line>
    grep -q "^$2=" "$1" || printf '%s\n' "$3" >>"$1"
}

find_binary() {
    for candidate in \
        "$OUR_DIR/$APP" \
        "$OUR_DIR/../target/release/$APP" \
        "$OUR_DIR/../target/debug/$APP" \
        "$BIN_FILE"; do
        if [ -f "$candidate" ] && [ -x "$candidate" ]; then
            abspath "$candidate"
            return 0
        fi
    done
    found=$(command -v "$APP" 2>/dev/null) || found=""
    if [ -n "$found" ]; then
        abspath "$found"
    fi
    return 0
}

find_icon() {
    for candidate in "$OUR_DIR/$APP.png" "$OUR_DIR/icon.png" "$OUR_DIR/../app/icons/icon.png"; do
        if [ -f "$candidate" ]; then
            abspath "$candidate"
            return 0
        fi
    done
    return 0
}

register_handler() {
    if command -v update-desktop-database >/dev/null 2>&1; then
        update-desktop-database "$APP_DIR" >/dev/null 2>&1 ||
            warn "update-desktop-database failed; the entry may only appear after the next login"
    else
        say "update-desktop-database not found - skipped (the entry appears after the next login)"
    fi

    if command -v xdg-mime >/dev/null 2>&1; then
        xdg-mime default "$APP.desktop" "x-scheme-handler/$SCHEME" >/dev/null 2>&1 ||
            warn "xdg-mime could not set the lanomat:// handler; pick the launcher in your desktop settings"
    else
        warn "xdg-mime not found - lanomat:// links are not assigned yet (see LIESMICH.txt)"
    fi
}

uninstall_all() {
    removed=0
    for file in "$DESKTOP_FILE" "$ICON_FILE" "$BIN_FILE"; do
        if [ -e "$file" ]; then
            rm -f "$file"
            say "removed $file"
            removed=1
        fi
    done
    [ "$removed" = 1 ] || say "nothing to remove"
    if command -v update-desktop-database >/dev/null 2>&1; then
        update-desktop-database "$APP_DIR" >/dev/null 2>&1 || true
    fi
}

install_all() {
    src_bin=$GIVEN_BIN
    if [ -n "$src_bin" ]; then
        [ -e "$src_bin" ] || die "no such file: $src_bin"
        [ -x "$src_bin" ] || die "not executable: $src_bin"
        src_bin=$(abspath "$src_bin")
    else
        src_bin=$(find_binary)
    fi
    [ -n "$src_bin" ] || die "lanomat-launcher not found; pass --bin /path/to/lanomat-launcher"

    mkdir -p "$BIN_DIR" "$APP_DIR" "$ICON_DIR"

    if [ "$src_bin" != "$BIN_FILE" ]; then
        # Copy next to the target and rename: a running launcher can be replaced that way.
        TMP_BIN=$BIN_DIR/.$APP.new.$$
        cp "$src_bin" "$TMP_BIN"
        chmod 0755 "$TMP_BIN"
        mv -f "$TMP_BIN" "$BIN_FILE"
        TMP_BIN=""
        say "binary  $BIN_FILE"
    else
        chmod 0755 "$BIN_FILE"
        say "binary  $BIN_FILE (already in place)"
    fi

    src_icon=$(find_icon)
    if [ -n "$src_icon" ]; then
        cp "$src_icon" "$ICON_FILE"
        chmod 0644 "$ICON_FILE"
        say "icon    $ICON_FILE"
    else
        # A missing icon is cosmetic: Icon=lanomat-launcher simply resolves to nothing.
        warn "no icon next to the script - the menu entry stays without a picture"
    fi

    TMP_DESKTOP=$APP_DIR/.$APP.desktop.new.$$
    if [ -f "$OUR_DIR/$APP.desktop" ]; then
        cp "$OUR_DIR/$APP.desktop" "$TMP_DESKTOP"
    else
        print_desktop >"$TMP_DESKTOP"
    fi
    rewrite_key "$TMP_DESKTOP" Exec "Exec=$(desktop_exec_value "$BIN_FILE") %u"
    ensure_key "$TMP_DESKTOP" MimeType "MimeType=x-scheme-handler/$SCHEME;"
    ensure_key "$TMP_DESKTOP" Icon "Icon=$APP"
    ensure_key "$TMP_DESKTOP" Terminal "Terminal=false"
    ensure_key "$TMP_DESKTOP" StartupWMClass "StartupWMClass=$APP"
    chmod 0644 "$TMP_DESKTOP"
    mv -f "$TMP_DESKTOP" "$DESKTOP_FILE"
    TMP_DESKTOP=""
    say "entry   $DESKTOP_FILE"

    if command -v desktop-file-validate >/dev/null 2>&1; then
        if validate_out=$(desktop-file-validate "$DESKTOP_FILE" 2>&1); then
            :
        else
            warn "desktop-file-validate: $validate_out"
        fi
    fi

    [ "$REGISTER" = 0 ] || register_handler

    say ""
    say "Fertig: lanomat://-Links öffnen jetzt den Launcher, z. B."
    say "  \"$BIN_FILE\" lanomat://install/unreal-tournament-2004"
    say "Entfernen: $PROG --uninstall"
}

if [ "$UNINSTALL" = 1 ]; then
    uninstall_all
else
    install_all
fi
