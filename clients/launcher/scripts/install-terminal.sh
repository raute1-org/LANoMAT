#!/bin/sh
# Turn this machine into a LANoMAT gaming terminal or arcade cabinet.
#
#   ./install-terminal.sh lanomat-launcher-<version>-linux-x86_64.tar.gz
#   ./install-terminal.sh --arcade --name "Arcade 3" lanomat-launcher
#   ./install-terminal.sh --kiosk --no-autostart lanomat-launcher
#   ./install-terminal.sh --uninstall
#
# What it does (no root, no network, no secrets, safe to re-run):
#   * installs the binary, the desktop entry and the icon through the sibling
#     install-desktop.sh (so lanomat:// links work and both installers agree),
#   * --kiosk / --arcade start the launcher fullscreen: the flag is added to the
#     installed desktop entry, never duplicated on a second run,
#   * writes ~/.config/autostart/lanomat-launcher.desktop (or a systemd user
#     unit with --autostart=systemd) so the terminal boots into the launcher,
#   * --name writes LANOMAT_TERMINAL_NAME into that autostart entry, which is
#     the name the orga monitor shows for this cabinet (default: hostname).
#
# --uninstall removes exactly the files this script and install-desktop.sh add,
# and is safe to repeat. Tarballs are unpacked into a temp dir that is removed
# on exit; a bare binary next to this script needs no unpacking.
set -eu

PROG=${0##*/}
APP=lanomat-launcher

UNINSTALL=0
ARCADE=0
KIOSK=0
AUTOSTART=1
AUTOSTART_MODE=desktop
TERMINAL_NAME=""
GIVEN_SRC=""

die() { printf '%s: %s\n' "$PROG" "$*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }
warn() { printf '%s: %s\n' "$PROG" "$*" >&2; }

usage() {
    cat <<'USAGE'
Usage: install-terminal.sh [options] [tarball-or-binary]

  --bin PATH            use this launcher tarball/binary instead of the one
                        next to the script
  --arcade              cabinet preset: fullscreen (without it: a desk app)
  --kiosk               locked fullscreen: Escape cannot leave it
  --no-autostart        install but do not start the launcher at login
  --autostart MODE      desktop (default, ~/.config/autostart) or
                        systemd (a --user unit wanted by graphical-session)
  --name NAME           terminal name for the orga monitor
                        (LANOMAT_TERMINAL_NAME; default: the hostname)
  --uninstall           remove binary, entry, icon and autostart again
  -h, --help            show this help

The binary, desktop entry and icon come from the sibling install-desktop.sh;
XDG_CONFIG_HOME and XDG_DATA_HOME are honoured, LANOMAT_BIN_DIR overrides
~/.local/bin. No root, no secrets, and running it twice changes nothing.
USAGE
}

while [ $# -gt 0 ]; do
    case $1 in
        --uninstall) UNINSTALL=1 ;;
        --arcade) ARCADE=1 ;;
        --kiosk) KIOSK=1 ;;
        --no-autostart) AUTOSTART=0 ;;
        --autostart)
            [ $# -ge 2 ] || die "--autostart needs desktop or systemd"
            AUTOSTART_MODE=$2
            shift
            ;;
        --autostart=*)
            AUTOSTART_MODE=${1#--autostart=}
            ;;
        --name)
            [ $# -ge 2 ] || die "--name needs a value"
            TERMINAL_NAME=$2
            shift
            ;;
        --name=*)
            TERMINAL_NAME=${1#--name=}
            ;;
        --bin)
            [ $# -ge 2 ] || die "--bin needs a path"
            GIVEN_SRC=$2
            shift
            ;;
        -h | --help)
            usage
            exit 0
            ;;
        -*)
            usage >&2
            die "unknown argument: $1"
            ;;
        *)
            [ -z "$GIVEN_SRC" ] || die "more than one source given: $GIVEN_SRC and $1"
            GIVEN_SRC=$1
            ;;
    esac
    shift
done

case $AUTOSTART_MODE in
    desktop | systemd) ;;
    *)
        usage >&2
        die "--autostart must be desktop or systemd, not '$AUTOSTART_MODE'"
        ;;
esac

if [ -n "$TERMINAL_NAME" ]; then
    # The name lands in a .desktop Exec= line and in the server's 60-character
    # column: letters, digits, space, dot, dash and underscore only.
    case $TERMINAL_NAME in
        *[!A-Za-z0-9\ ._-]*) die "--name accepts letters, digits, space, . _ and - only: '$TERMINAL_NAME'" ;;
    esac
    if [ "${#TERMINAL_NAME}" -gt 60 ]; then
        die "--name is longer than 60 characters"
    fi
    case $TERMINAL_NAME in
        *[![:space:]]*) ;;
        *) die "--name must not be blank" ;;
    esac
fi

[ -n "${HOME:-}" ] || die "HOME is not set"
[ -d "$HOME" ] || die "HOME does not exist: $HOME"

CONFIG_HOME=${XDG_CONFIG_HOME:-$HOME/.config}
case $CONFIG_HOME in
    /*) ;;
    *) CONFIG_HOME=$HOME/.config ;;
esac
DATA_HOME=${XDG_DATA_HOME:-$HOME/.local/share}
case $DATA_HOME in
    /*) ;;
    *) DATA_HOME=$HOME/.local/share ;;
esac
BIN_DIR=${LANOMAT_BIN_DIR:-$HOME/.local/bin}
BIN_FILE=$BIN_DIR/$APP
DESKTOP_FILE=$DATA_HOME/applications/$APP.desktop
AUTOSTART_FILE=$CONFIG_HOME/autostart/$APP.desktop
UNIT_FILE=$CONFIG_HOME/systemd/user/$APP.service

case $0 in
    */*) OUR_DIR=$(cd "${0%/*}" && pwd) ;;
    *) OUR_DIR=$(pwd) ;;
esac

WORK_DIR=""
cleanup() { [ -z "$WORK_DIR" ] || rm -rf "$WORK_DIR"; }
trap cleanup 0 1 2 15

abspath() { # <existing path>
    case $1 in
        /*) printf '%s' "$1" ;;
        *) printf '%s/%s' "$(cd "$(dirname "$1")" && pwd)" "$(basename "$1")" ;;
    esac
}

# The installed desktop entry is the single source of truth for how the binary
# must be quoted (the path may have spaces). Strip the %u field code; the
# autostart entry and the systemd unit reuse the rest verbatim.
installed_exec() {
    [ -f "$DESKTOP_FILE" ] || die "no desktop entry at $DESKTOP_FILE - run without --uninstall first"
    exec_line=$(grep -m1 '^Exec=' "$DESKTOP_FILE" | sed -e 's/^Exec=//' -e 's/ %u$//')
    [ -n "$exec_line" ] || die "the desktop entry has an empty Exec= line"
    printf '%s' "$exec_line"
}

# Add a flag before the %u field code of the installed entry, once.
add_exec_flag() { # <file> <flag>
    add_flag_file=$1
    add_flag_value=$2
    awk -v flag="$add_flag_value" '
        index($0, "Exec=") == 1 {
            if (index($0, flag) > 0) { print; next }
            line = $0
            # POSIX awk has no three-argument sub(); trim the trailing field
            # code by hand instead.
            if (line ~ / %u$/) {
                line = substr(line, 1, length(line) - 3) " " flag " %u"
            } else {
                line = line " " flag
            }
            print line
            next
        }
        { print }
    ' "$add_flag_file" > "$add_flag_file.new.$$" || die "cannot rewrite $add_flag_file"
    mv -f "$add_flag_file.new.$$" "$add_flag_file"
}

unpack_source() { # sets SRC_BIN, and SRC_DIR when the source was unpacked
    src=$1

    [ -e "$src" ] || die "no such file: $src"
    src=$(abspath "$src")
    SRC_DIR=""

    case $src in
        *.tar.gz | *.tgz | *.tar)
            command -v tar >/dev/null 2>&1 || die "tar is needed to unpack $src"
            WORK_DIR=$(mktemp -d "${TMPDIR:-/tmp}/lanomat-terminal.XXXXXX") ||
                die "cannot create a temp directory"
            tar -xf "$src" -C "$WORK_DIR" || die "cannot unpack $src"
            found=$(find "$WORK_DIR" -type f -name "$APP" | head -n 1)
            [ -n "$found" ] || die "no $APP binary inside $src"
            SRC_DIR=$(dirname "$found")
            SRC_BIN=$found
            ;;
        *)
            [ -f "$src" ] || die "not a file: $src"
            if [ ! -x "$src" ]; then
                # A freshly copied release binary can lose its mode bit.
                chmod 0755 "$src" 2>/dev/null || true
                [ -x "$src" ] || die "not executable: $src"
            fi
            SRC_BIN=$src
            ;;
    esac
}

installer_for() { # the install-desktop.sh that belongs to this source
    if [ -n "${SRC_DIR:-}" ] && [ -f "$SRC_DIR/install-desktop.sh" ]; then
        printf '%s' "$SRC_DIR/install-desktop.sh"
        return 0
    fi

    if [ -f "$OUR_DIR/install-desktop.sh" ]; then
        printf '%s' "$OUR_DIR/install-desktop.sh"
    fi

    return 0
}

write_autostart() {
    launch_cmd=$1
    mkdir -p "$CONFIG_HOME/autostart"

    if [ -n "$TERMINAL_NAME" ]; then
        exec_value="env LANOMAT_TERMINAL_NAME=\"$TERMINAL_NAME\" $launch_cmd"
    else
        exec_value=$launch_cmd
    fi

    cat > "$AUTOSTART_FILE" <<ENTRY
[Desktop Entry]
Type=Application
Name=LANoMAT Launcher (Terminal)
Comment=Startet den LANoMAT Launcher beim Anmelden
Exec=$exec_value
Terminal=false
StartupNotify=false
X-GNOME-Autostart-enabled=true
X-LANoMAT-Terminal=true
ENTRY
    chmod 0644 "$AUTOSTART_FILE"
    say "autostart $AUTOSTART_FILE"
}

write_unit() { # <exec value, systemd-quoted>
    unit_exec=$1
    mkdir -p "$CONFIG_HOME/systemd/user"

    {
        printf '%s\n' '[Unit]'
        printf '%s\n' 'Description=LANoMAT Launcher (gaming terminal)'
        printf '%s\n' 'After=graphical-session.target'
        printf '%s\n' 'PartOf=graphical-session.target'
        printf '\n%s\n' '[Service]'
        printf '%s\n' 'Type=simple'
        printf 'ExecStart=%s\n' "$unit_exec"
        if [ -n "$TERMINAL_NAME" ]; then
            printf 'Environment="LANOMAT_TERMINAL_NAME=%s"\n' "$TERMINAL_NAME"
        fi
        printf '%s\n' 'Restart=on-failure'
        printf '%s\n' 'RestartSec=5'
        printf '\n%s\n' '[Install]'
        printf '%s\n' 'WantedBy=graphical-session.target'
    } > "$UNIT_FILE"
    chmod 0644 "$UNIT_FILE"
    say "unit      $UNIT_FILE"

    # Best effort: a machine without a running user manager still boots into a
    # session where the unit can be enabled later.
    if command -v systemctl >/dev/null 2>&1; then
        systemctl --user daemon-reload >/dev/null 2>&1 ||
            warn "systemctl --user daemon-reload failed"
        # Enable by path: the unit lives under $XDG_CONFIG_HOME, which the
        # running user manager does not necessarily search.
        systemctl --user enable "$UNIT_FILE" >/dev/null 2>&1 ||
            warn "systemctl --user enable $APP.service failed"
    else
        say "systemctl not found - enable $APP.service in your session by hand"
    fi
}

disable_unit() {
    command -v systemctl >/dev/null 2>&1 || return 0
    # `disable` also removes the graphical-session.target.wants symlink, which
    # would otherwise dangle after the unit file is gone.
    systemctl --user disable "$UNIT_FILE" >/dev/null 2>&1 || true
    rm -f "$CONFIG_HOME/systemd/user/graphical-session.target.wants/$APP.service"
    systemctl --user daemon-reload >/dev/null 2>&1 || true
}

systemd_exec_value() { # <path> - quoted, % doubled (systemd specifier)
    value=$1
    value=$(printf '%s' "$value" | sed -e 's/%/%%/g')
    case $value in
        *[[:space:]]* | *'"'* | *'$'* | *\\* | *\`*)
            # shellcheck disable=SC2016 # the sed script is literal
            value=$(printf '%s' "$value" |
                sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/`/\\`/g' -e 's/\$/\\$/g')
            printf '"%s"' "$value"
            ;;
        *) printf '%s' "$value" ;;
    esac
}

install_terminal() {
    if [ -n "$GIVEN_SRC" ]; then
        unpack_source "$GIVEN_SRC"
    else
        for candidate in "$OUR_DIR/$APP" "$OUR_DIR/../target/release/$APP" "$BIN_FILE"; do
            if [ -f "$candidate" ] && [ -x "$candidate" ]; then
                SRC_DIR=$OUR_DIR
                SRC_BIN=$(abspath "$candidate")
                break
            fi
        done
        [ -n "${SRC_BIN:-}" ] || die "no $APP found; pass a tarball/binary or --bin PATH"
    fi

    installer=$(installer_for)
    [ -n "$installer" ] ||
        die "install-desktop.sh not found next to this script or the source; a launcher tarball ships it"

    say "== launcher"
    sh "$installer" --bin "$SRC_BIN"

    [ -f "$BIN_FILE" ] || die "the installer did not produce $BIN_FILE"
    [ -f "$DESKTOP_FILE" ] || die "the installer did not produce $DESKTOP_FILE"

    if [ "$ARCADE" = 1 ]; then
        add_exec_flag "$DESKTOP_FILE" "--arcade"
    fi
    if [ "$KIOSK" = 1 ]; then
        add_exec_flag "$DESKTOP_FILE" "--kiosk"
    fi
    if [ "$ARCADE" = 1 ] || [ "$KIOSK" = 1 ]; then
        say "arcade    the desktop entry starts fullscreen"
    fi

    launch_cmd=$(installed_exec)
    if [ "$ARCADE" = 1 ]; then
        case $launch_cmd in
            *--arcade*) ;;
            *) launch_cmd="$launch_cmd --arcade" ;;
        esac
    fi
    if [ "$KIOSK" = 1 ]; then
        case $launch_cmd in
            *--kiosk*) ;;
            *) launch_cmd="$launch_cmd --kiosk" ;;
        esac
    fi

    if [ "$AUTOSTART" = 1 ]; then
        case $AUTOSTART_MODE in
            systemd)
                rm -f "$AUTOSTART_FILE"
                # The unit starts the binary, not the .desktop quoting; rebuild
                # it from the installed path so systemd owns the command line.
                unit_exec=$(systemd_exec_value "$BIN_FILE")
                if [ "$ARCADE" = 1 ]; then
                    unit_exec="$unit_exec --arcade"
                fi
                if [ "$KIOSK" = 1 ]; then
                    unit_exec="$unit_exec --kiosk"
                fi
                write_unit "$unit_exec"
                ;;
            desktop)
                if [ -f "$UNIT_FILE" ]; then
                    disable_unit
                    rm -f "$UNIT_FILE"
                    say "unit removed (switched to autostart)"
                fi
                write_autostart "$launch_cmd"
                ;;
        esac
    else
        if [ -f "$UNIT_FILE" ]; then
            disable_unit
        fi
        rm -f "$AUTOSTART_FILE" "$UNIT_FILE"
        say "autostart none (--no-autostart)"
    fi

    say ""
    say "Fertig: dieses Geraet ist ein LANoMAT-Terminal."
    say "  Start:    \"$BIN_FILE\""
    say "  Name:     ${TERMINAL_NAME:-$(hostname 2>/dev/null || printf 'Hostname')} (LANOMAT_TERMINAL_NAME)"
    say "  Monitor:  die Terminal-Liste im Admin-Panel (alle 30 s ein Heartbeat)"
    say "Entfernen: $PROG --uninstall"
}

uninstall_all() {
    if [ -f "$UNIT_FILE" ]; then
        # Disable while the unit still exists: a fresh user manager rejects
        # `disable` for a unit file that has already been removed.
        disable_unit
    fi

    for file in "$AUTOSTART_FILE" "$UNIT_FILE"; do
        if [ -e "$file" ]; then
            rm -f "$file"
            say "removed $file"
        fi
    done

    installer=$OUR_DIR/install-desktop.sh
    [ -f "$installer" ] || die "install-desktop.sh not found next to this script"
    sh "$installer" --uninstall
}

if [ "$UNINSTALL" = 1 ]; then
    uninstall_all
else
    install_terminal
fi
