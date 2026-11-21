#!/usr/bin/env bash
# Build the three portable UT2004 3374 archives from a flat full-game tree and the
# OldUnreal 3374 patches:
#
#   ut2004-portable-3374-windows.zip   assets + Windows patch System
#   ut2004-portable-3374-linux.zip     assets + Linux patch System
#   ut2004-portable-3374-macos.zip     assets + UT2004.app from the macOS DMG
#
#   scripts/ops/ut2004/build-portable.sh \
#       --source /srv/ut2004/flat-tree \
#       --patches /srv/ut2004/patches \
#       --out clients-dist/ut2004
#
# The source may be the flat tree itself or a ZIP of it. One wrapper folder
# (`UT2004/`) is stripped, so the archives always start at Maps/, Textures/,
# System/ and so on. The source `System/` (and any SystemLocalized/SystemARM64/
# SystemPPC64LE left by an earlier patch) is deleted before the OS patch is
# applied: a retail 3369 System mixes 32-bit binaries and old packages with the
# 3374 tree, and OldUnreal's manual install explicitly says not to copy it. The
# patch carries the complete replacement System; merging the two is what makes
# the game (especially on Linux) refuse to start or fall back to old menus.
#
# Why these patches must never be mixed:
#   Windows: OldUnreal-UT2004Patch3374-Windows-*.zip -> System/UT2004.exe (PE64),
#            System/D3D11Drv.dll, System/Build.ini v3374, plus Help/, Sounds/,
#            Textures/, Web/, SystemLocalized/, SystemARM64/.
#   Linux:   OldUnreal-UT2004Patch3374-Linux-*.tar.bz2 -> System/UT2004 (ELF64),
#            System/ut2004-bin-amd64 -> UT2004, the OpenAL/SDL libraries,
#            System/Build.ini v3374, plus Help/, Sounds/, Textures/, Web/,
#            SystemLocalized/, SystemARM64/, SystemPPC64LE/.
#   macOS:   OldUnreal-UT2004Patch3374-macOS*.dmg -> UT2004.app, copied to the
#            archive root. The DMG ships no Build.ini, so this script writes a
#            small root Build.ini (`Label=UT2004_v3374_macOS`); the app's own
#            version stays in Contents/Info.plist (CFBundleShortVersionString).
#            The app is not modified, so its code signature stays intact.
#
# Archive layout (flat, no wrapper folder):
#   Windows/Linux: Maps/ Textures/ Sounds/ StaticMeshes/ Animations/ KarmaData/
#                  Music/ Prefabs/ Speech/ Web/ Help/ System/ ...
#   macOS:         Maps/ Textures/ ... UT2004.app/ Build.ini
#                  Put UT2004.app in ~/Applications (or the install target) and
#                  the asset folders in ~/Library/Application Support/UT2004,
#                  as the recipe's macOS step describes.
#
# ZIP vs tar.bz2: the Linux patch is a tar.bz2 because tar preserves symlinks
# (`ut2004-bin-amd64`, `libopenal.so.1`, `libSDL3.so.0`) and the 0755 mode.
# A ZIP has no dependable symlink semantics in the launcher (its zip extractor
# skips symlink entries), so `zip` follows the links and stores regular files
# under those names; the Unix mode still rides in the ZIP external attributes
# (`unzip -Z -l` shows -rwx). The launcher's zip extractor does not restore
# modes, but it marks System/UT2004, System/ut2004-bin-amd64 and the app binary
# executable after extraction. If a deployment needs exact symlinks/modes with
# no post-processing, publish a Linux tar.bz2; the recipe accepts
# `ut2004-portable-3374-linux*` as either ZIP or tar.bz2.
#
# Launcher matching: the recipe declares one `base` requirement per OS and the
# launcher picks the one whose `os` fits the plan. The names above match:
#   windows  `*UT2004*portable*.zip` (and the exact short names)
#   linux    `ut2004-portable-3374-linux*`
#   macos    `ut2004-portable-3374-macos*`
# Matching is case-insensitive. Upload all three under these names; the OS
# suffix keeps a Linux/macOS plan away from the Windows archive. The Windows
# requirement's broad pattern also matches the other two names, so the Windows
# archive is the one a Windows plan must find.
#
# Verification: after zipping, every archive is CRC-tested (`unzip -t`), its
# entry list is checked with `unzip -Z1` and `bsdtar -tf`, `Maps/DM-Rankin.ut2`
# and the OS system files are required, Build.ini must say 3374, and the Linux
# ELF / Windows PE / macOS Mach-O magic plus the executable bit are inspected.
# Size and SHA-256 are printed for each archive.
#
# Dependencies: bash 4+, unzip, zip, tar (bzip2), find, od, and either
# sha256sum or shasum. macOS DMG extraction needs `7zz`; if it is not on PATH
# (and LANOMAT_7ZZ is unset), the script downloads 7-Zip 25.01 for Linux x64
# from 7-zip.org into ~/.local/share/lanomat/tools/7zz (1,571,044 bytes,
# SHA-256 verified). `bsdtar` is optional and only adds a second listing check.
#
# Exit codes: 0 ok, 2 usage, 3 missing dependency, 4 unusable input
# (source/patches), 5 build or archive verification failed.
#
# No secrets are read or written; the work directory is private and removed on
# exit unless --keep is given. No pkill/process matching is used.
set -Eeuo pipefail
set -o pipefail

readonly SCRIPT_NAME="${0##*/}"
readonly PATCH_VERSION="3374"
readonly SEVEN_ZIP_URL="https://www.7-zip.org/a/7z2501-linux-x64.tar.xz"
readonly SEVEN_ZIP_SHA256="4ca3b7c6f2f67866b92622818b58233dc70367be2f36b498eb0bdeaaa44b53f4"
readonly SEVEN_ZIP_SIZE="1571044"

readonly EXIT_USAGE=2
readonly EXIT_DEP=3
readonly EXIT_INPUT=4
readonly EXIT_BUILD=5

SOURCE=""
PATCH_DIR=""
OUT_DIR=""
WORK_PARENT=""
KEEP=0
DRY_RUN=0
PARALLEL=0
WORK=""
SOURCE_TREE=""
WIN_PATCH=""
LINUX_PATCH=""
MAC_PATCH=""
SEVEN_ZIP=""
LOG_PREFIX=""

log() { printf '%s%s: %s\n' "${LOG_PREFIX}" "$SCRIPT_NAME" "$*" >&2; }
warn() { printf '%s%s: warning: %s\n' "${LOG_PREFIX}" "$SCRIPT_NAME" "$*" >&2; }
die() {
    local code=$1
    shift
    printf '%s%s: error: %s\n' "${LOG_PREFIX}" "$SCRIPT_NAME" "$*" >&2
    exit "$code"
}

usage() {
    cat <<'USAGE'
Build portable UT2004 3374 archives (Windows, Linux, macOS).

Usage:
  build-portable.sh --source <flat-tree-or-zip> --patches <dir> --out <dir>
                    [--work <dir>] [--keep] [--dry-run] [--parallel]

Required:
  --source <path>   Flat full-game tree (Maps/, Textures/, System/, ...) or a
                    ZIP of it. A single wrapper folder (UT2004/) is stripped.
  --patches <dir>   Directory holding the three OldUnreal 3374 assets:
                      OldUnreal-UT2004Patch3374-Windows-*.zip
                      OldUnreal-UT2004Patch3374-Linux-*.tar.bz2
                      OldUnreal-UT2004Patch3374-macOS*.dmg
  --out <dir>       Output directory for the three ZIPs (created if missing).

Optional:
  --work <dir>      Parent for the private scratch directory (default: $TMPDIR,
                    or /tmp). A unique ut2004-portable.XXXXXX is made inside.
  --keep            Keep the scratch tree (staged OS trees, extracted DMG) so
                    the result can be inspected. Removed on exit by default.
  --dry-run         Resolve inputs and print the plan; build nothing.
  --parallel        Build the three OS trees/archives concurrently (more disk
                    and CPU; the default is one after another).
  -h, --help        Show this help.

Environment:
  LANOMAT_7ZZ       Path to a 7zz binary. Otherwise 7zz from PATH is used, or
                    7-Zip 25.01 is downloaded once to
                    ~/.local/share/lanomat/tools/7zz (SHA-256 pinned).

Outputs (fixed names, flat layout):
  ut2004-portable-3374-windows.zip
  ut2004-portable-3374-linux.zip
  ut2004-portable-3374-macos.zip

Exit codes: 0 ok, 2 usage, 3 missing dependency, 4 unusable input,
            5 build or archive verification failed.

Examples:
  scripts/ops/ut2004/build-portable.sh \
      --source /srv/ut2004/ut2004-flat --patches /srv/ut2004/patches \
      --out clients-dist/ut2004

  scripts/ops/ut2004/build-portable.sh \
      --source ~/Downloads/UnrealTournament2004.zip \
      --patches ~/Downloads/ut2004-build/patches --out /tmp/ut2004-out \
      --work /tmp/opencode --keep
USAGE
}

need_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "$EXIT_DEP" "missing command: $1"
}

file_size() {
    stat -c %s "$1" 2>/dev/null || stat -f %z "$1"
}

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum -- "$1" | cut -d' ' -f1
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 -- "$1" | cut -d' ' -f1
    else
        die "$EXIT_DEP" "neither sha256sum nor shasum is available"
    fi
}

# Absolute path for a path that may not exist yet; only its parent must exist.
abspath() {
    local path=$1
    if [ -d "$path" ]; then
        (cd "$path" && pwd)
    elif [ -e "$path" ]; then
        printf '%s/%s\n' "$(cd "$(dirname "$path")" && pwd)" "$(basename "$path")"
    else
        mkdir -p "$(dirname "$path")" || die "$EXIT_INPUT" "cannot create parent of $path"
        printf '%s/%s\n' "$(cd "$(dirname "$path")" && pwd)" "$(basename "$path")"
    fi
}

cleanup() {
    local status=$?
    trap - EXIT HUP INT TERM
    if [ "${KEEP}" -eq 0 ] && [ -n "${WORK}" ] && [ -d "${WORK}" ]; then
        rm -rf -- "${WORK}"
    fi
    exit "$status"
}

trap cleanup EXIT HUP INT TERM

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --source)
                [ "$#" -ge 2 ] || die "$EXIT_USAGE" "--source needs a value"
                SOURCE=$2
                shift 2
                ;;
            --source=*)
                SOURCE=${1#*=}
                shift
                ;;
            --patches)
                [ "$#" -ge 2 ] || die "$EXIT_USAGE" "--patches needs a value"
                PATCH_DIR=$2
                shift 2
                ;;
            --patches=*)
                PATCH_DIR=${1#*=}
                shift
                ;;
            --out)
                [ "$#" -ge 2 ] || die "$EXIT_USAGE" "--out needs a value"
                OUT_DIR=$2
                shift 2
                ;;
            --out=*)
                OUT_DIR=${1#*=}
                shift
                ;;
            --work)
                [ "$#" -ge 2 ] || die "$EXIT_USAGE" "--work needs a value"
                WORK_PARENT=$2
                shift 2
                ;;
            --work=*)
                WORK_PARENT=${1#*=}
                shift
                ;;
            --keep)
                KEEP=1
                shift
                ;;
            --dry-run)
                DRY_RUN=1
                shift
                ;;
            --parallel)
                PARALLEL=1
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            --)
                shift
                break
                ;;
            *)
                die "$EXIT_USAGE" "unknown argument: $1 (try --help)"
                ;;
        esac
    done

    [ "$#" -eq 0 ] || die "$EXIT_USAGE" "unexpected argument: $1"

    [ -n "$SOURCE" ] || die "$EXIT_USAGE" "--source is required (try --help)"
    [ -n "$PATCH_DIR" ] || die "$EXIT_USAGE" "--patches is required (try --help)"
    [ -n "$OUT_DIR" ] || die "$EXIT_USAGE" "--out is required (try --help)"
}

check_dependencies() {
    need_cmd unzip
    need_cmd zip
    need_cmd tar
    need_cmd find
    need_cmd od
    need_cmd stat
    if ! command -v sha256sum >/dev/null 2>&1 && ! command -v shasum >/dev/null 2>&1; then
        die "$EXIT_DEP" "neither sha256sum nor shasum is available"
    fi
}

# Locate exactly one patch matching the glob. The OldUnreal asset names carry
# a short commit suffix, so a fixed filename would rot with each release.
find_patch() {
    local label=$1
    local pattern=$2
    local -a hits=()
    local hit

    while IFS= read -r -d '' hit; do
        hits+=("$hit")
    done < <(find "$PATCH_DIR" -maxdepth 1 -type f -name "$pattern" -print0)

    if [ "${#hits[@]}" -eq 0 ]; then
        die "$EXIT_INPUT" "no $label patch matching '$pattern' in $PATCH_DIR"
    fi
    if [ "${#hits[@]}" -gt 1 ]; then
        printf '%s\n' "${hits[@]}" >&2
        die "$EXIT_INPUT" "more than one $label patch matches '$pattern'; keep one of the listed files"
    fi

    printf '%s\n' "${hits[0]}"
}

resolve_patches() {
    [ -d "$PATCH_DIR" ] || die "$EXIT_INPUT" "patch directory does not exist: $PATCH_DIR"

    WIN_PATCH=$(find_patch "Windows" "OldUnreal-UT2004Patch3374-Windows-*.zip")
    LINUX_PATCH=$(find_patch "Linux" "OldUnreal-UT2004Patch3374-Linux-*.tar.bz2")
    MAC_PATCH=$(find_patch "macOS" "OldUnreal-UT2004Patch3374-macOS*.dmg")
}

# A flat game tree has Maps/ or System/ at its root. If the ZIP carried one
# wrapper folder (UT2004/), descend to the first System/ or Maps/ marker.
resolve_tree() {
    local dir=$1
    local marker

    if [ -d "$dir/System" ] || [ -d "$dir/Maps" ]; then
        printf '%s\n' "$dir"
        return 0
    fi

    marker=$(find "$dir" -mindepth 2 -maxdepth 4 \
        -name '__MACOSX' -prune -o \
        -type d -name 'System' -print -quit)
    if [ -z "$marker" ]; then
        marker=$(find "$dir" -mindepth 2 -maxdepth 4 \
            -name '__MACOSX' -prune -o \
            -type d -name 'Maps' -print -quit)
    fi
    [ -n "$marker" ] || return 1

    printf '%s\n' "$(dirname "$marker")"
}

prepare_source() {
    if [ -d "$SOURCE" ]; then
        SOURCE_TREE=$(resolve_tree "$SOURCE") ||
            die "$EXIT_INPUT" "no flat UT2004 tree under $SOURCE (need System/ or Maps/)"
    elif [ -f "$SOURCE" ]; then
        case "${SOURCE,,}" in
            *.zip) ;;
            *) die "$EXIT_INPUT" "source must be a flat directory or a .zip: $SOURCE" ;;
        esac
        mkdir -p "$WORK/source"
        log "unpacking source ZIP ${SOURCE##*/}"
        unzip -q -o "$SOURCE" -d "$WORK/source" ||
            die "$EXIT_INPUT" "could not unpack source ZIP: $SOURCE"
        SOURCE_TREE=$(resolve_tree "$WORK/source") ||
            die "$EXIT_INPUT" "the source ZIP has no flat UT2004 tree (need System/ or Maps/)"
    else
        die "$EXIT_INPUT" "source does not exist: $SOURCE"
    fi

    [ -d "$SOURCE_TREE/Maps" ] ||
        die "$EXIT_INPUT" "source tree has no Maps/: $SOURCE_TREE"
    [ -d "$SOURCE_TREE/Textures" ] ||
        die "$EXIT_INPUT" "source tree has no Textures/: $SOURCE_TREE"
    if [ ! -f "$SOURCE_TREE/Maps/DM-Rankin.ut2" ]; then
        warn "source has no Maps/DM-Rankin.ut2; the archive check will fail unless the game tree is complete"
    fi

    log "source tree: $SOURCE_TREE"
}

make_work_dir() {
    local parent
    if [ -n "$WORK_PARENT" ]; then
        parent=$WORK_PARENT
        mkdir -p "$parent" || die "$EXIT_INPUT" "cannot create work parent: $parent"
    else
        parent=${TMPDIR:-/tmp}
        [ -d "$parent" ] || parent=/tmp
    fi

    WORK=$(mktemp -d "$parent/ut2004-portable.XXXXXX") ||
        die "$EXIT_INPUT" "cannot create scratch directory under $parent"
    log "work directory: $WORK"
}

ensure_seven_zip() {
    local source=""
    local cache="${XDG_DATA_HOME:-$HOME/.local/share}/lanomat/tools/7zz"

    if [ -n "${LANOMAT_7ZZ:-}" ]; then
        [ -x "$LANOMAT_7ZZ" ] || die "$EXIT_DEP" "LANOMAT_7ZZ is not executable: $LANOMAT_7ZZ"
        source=$LANOMAT_7ZZ
    elif command -v 7zz >/dev/null 2>&1; then
        source=$(command -v 7zz)
    elif [ -x "$cache" ]; then
        source=$cache
    else
        need_cmd curl
        local dl="$WORK/7zz-download"
        mkdir -p "$dl" "$(dirname "$cache")" ||
            die "$EXIT_DEP" "cannot create 7zz cache directory: $(dirname "$cache")"
        log "downloading 7zz 25.01 to $cache"
        curl -fL --retry 3 --connect-timeout 20 --max-time 300 \
            -o "$dl/7z.tar.xz" "$SEVEN_ZIP_URL" ||
            die "$EXIT_DEP" "could not download $SEVEN_ZIP_URL"
        [ "$(file_size "$dl/7z.tar.xz")" = "$SEVEN_ZIP_SIZE" ] ||
            die "$EXIT_DEP" "7zz download has the wrong size"
        [ "$(sha256_of "$dl/7z.tar.xz")" = "$SEVEN_ZIP_SHA256" ] ||
            die "$EXIT_DEP" "7zz download failed its SHA-256 check"
        tar -xJf "$dl/7z.tar.xz" -C "$dl" ||
            die "$EXIT_DEP" "could not unpack the 7zz tarball"
        [ -x "$dl/7zz" ] || die "$EXIT_DEP" "the 7zz tarball has no 7zz binary"
        # Install atomically: a concurrent build may share the cache and must
        # never see a half-written binary.
        if cp "$dl/7zz" "$cache.tmp.$$" && chmod 0755 "$cache.tmp.$$"; then
            mv -f "$cache.tmp.$$" "$cache" ||
                die "$EXIT_DEP" "could not install 7zz at $cache"
        else
            rm -f -- "$cache.tmp.$$"
            die "$EXIT_DEP" "could not install 7zz at $cache"
        fi
        rm -rf -- "$dl"
        source=$cache
    fi

    # Work on a private copy. Another process (or a sibling build) may prune
    # the shared cache while this build is running; the work copy stays put.
    cp "$source" "$WORK/7zz" ||
        die "$EXIT_DEP" "could not copy 7zz into $WORK"
    chmod 0755 "$WORK/7zz" ||
        die "$EXIT_DEP" "could not make the private 7zz executable"
    SEVEN_ZIP="$WORK/7zz"
    log "7zz: $SEVEN_ZIP (from $source)"
}

# 7zz unpacks the DMG's HFS+ volume. The app sits under one wrapper folder
# (OldUnreal-UT2004Patch-3374/UT2004.app); find it instead of hard-coding the
# release's folder name.
extract_mac_app() {
    local dmg=$1
    local out=$2
    local app

    rm -rf -- "$out"
    mkdir -p "$out" || die "$EXIT_BUILD" "cannot create DMG scratch directory: $out"
    log "unpacking macOS DMG ${dmg##*/}"
    "$SEVEN_ZIP" x -y -bso0 -bsp0 -o"$out" "$dmg" >/dev/null ||
        die "$EXIT_BUILD" "7zz could not unpack the macOS DMG"

    app=$(find "$out" -type d -name 'UT2004.app' -print -quit)
    [ -n "$app" ] || die "$EXIT_BUILD" "the macOS DMG contains no UT2004.app"
    [ -f "$app/Contents/MacOS/UT2004" ] ||
        die "$EXIT_BUILD" "UT2004.app has no Contents/MacOS/UT2004"

    printf '%s\n' "$app"
}

strip_mac_junk() {
    local app=$1
    find "$app" -name '._*' -delete 2>/dev/null || true
    find "$app" -name '.DS_Store' -delete 2>/dev/null || true
    if command -v xattr >/dev/null 2>&1; then
        xattr -dr com.apple.quarantine "$app" 2>/dev/null || true
    fi
}

apply_patch() {
    local os=$1
    local tree=$2

    case "$os" in
        windows)
            log "applying Windows patch ${WIN_PATCH##*/}"
            unzip -q -o "$WIN_PATCH" -d "$tree" ||
                die "$EXIT_BUILD" "could not extract the Windows patch"
            ;;
        linux)
            log "applying Linux patch ${LINUX_PATCH##*/}"
            tar -xjf "$LINUX_PATCH" -C "$tree" ||
                die "$EXIT_BUILD" "could not extract the Linux patch"
            ;;
        macos)
            local app
            app=$(extract_mac_app "$MAC_PATCH" "$WORK/dmg-macos")
            rm -rf -- "$tree/UT2004.app"
            cp -a "$app" "$tree/UT2004.app" ||
                die "$EXIT_BUILD" "could not copy UT2004.app into the macOS tree"
            strip_mac_junk "$tree/UT2004.app"
            chmod 0755 "$tree/UT2004.app/Contents/MacOS/UT2004" ||
                die "$EXIT_BUILD" "could not mark the macOS app binary executable"
            # The DMG app has no Build.ini. Keep the app untouched (signature)
            # and put a small version marker at the archive root instead.
            {
                printf '[BuildVersion]\n'
                printf 'Label=UT2004_v%s_macOS\n' "$PATCH_VERSION"
            } > "$tree/Build.ini" || die "$EXIT_BUILD" "could not write macOS Build.ini"
            ;;
        *)
            die "$EXIT_BUILD" "unknown OS: $os"
            ;;
    esac
}

check_file() {
    [ -f "$1" ] || die "$EXIT_BUILD" "missing file: $1"
}

is_elf() {
    local magic
    magic=$(od -An -tx1 -N4 "$1" | tr -d ' \n')
    [ "$magic" = "7f454c46" ]
}

is_pe() {
    local magic
    magic=$(od -An -tx1 -N2 "$1" | tr -d ' \n')
    [ "$magic" = "4d5a" ]
}

is_macho() {
    local magic
    magic=$(od -An -tx1 -N4 "$1" | tr -d ' \n')
    case "$magic" in
        cffaedfe|cefaedfe|cafebabe|bebafeca|feedfacf) return 0 ;;
        *) return 1 ;;
    esac
}

check_build_ini() {
    local ini=$1
    check_file "$ini"
    local hits
    hits=$(grep -ci -- '3374' "$ini" || true)
    [ "${hits:-0}" -gt 0 ] ||
        die "$EXIT_BUILD" "Build.ini does not report 3374: ${ini#*/tree-}"
}

verify_tree() {
    local os=$1
    local tree=$2

    check_file "$tree/Maps/DM-Rankin.ut2"

    case "$os" in
        windows)
            check_file "$tree/System/UT2004.exe"
            is_pe "$tree/System/UT2004.exe" ||
                die "$EXIT_BUILD" "System/UT2004.exe is not a PE binary"
            check_file "$tree/System/D3D11Drv.dll"
            check_build_ini "$tree/System/Build.ini"
            [ -d "$tree/SystemLocalized" ] ||
                warn "Windows patch produced no SystemLocalized/ (unexpected)"
            ;;
        linux)
            check_file "$tree/System/UT2004"
            is_elf "$tree/System/UT2004" ||
                die "$EXIT_BUILD" "System/UT2004 is not an ELF binary"
            [ -x "$tree/System/UT2004" ] ||
                die "$EXIT_BUILD" "System/UT2004 is not executable"
            [ -e "$tree/System/ut2004-bin-amd64" ] ||
                die "$EXIT_BUILD" "System/ut2004-bin-amd64 is missing"
            check_build_ini "$tree/System/Build.ini"
            ;;
        macos)
            check_file "$tree/UT2004.app/Contents/MacOS/UT2004"
            is_macho "$tree/UT2004.app/Contents/MacOS/UT2004" ||
                die "$EXIT_BUILD" "UT2004.app/Contents/MacOS/UT2004 is not a Mach-O binary"
            [ -x "$tree/UT2004.app/Contents/MacOS/UT2004" ] ||
                die "$EXIT_BUILD" "the macOS app binary is not executable"
            check_file "$tree/UT2004.app/Contents/Resources/System/Packages.md5"
            check_build_ini "$tree/Build.ini"
            ;;
        *)
            die "$EXIT_BUILD" "unknown OS: $os"
            ;;
    esac
}

zip_has_entry() {
    local list=$1
    local entry=$2
    grep -Fqx -- "$entry" "$list" ||
        die "$EXIT_BUILD" "archive is missing $entry"
}

zip_text_reports_3374() {
    local archive=$1
    local entry=$2
    local count
    count=$(unzip -p "$archive" "$entry" | grep -ci -- '3374' || true)
    [ "${count:-0}" -gt 0 ] ||
        die "$EXIT_BUILD" "$entry in the archive does not report 3374"
}

zip_magic() {
    local archive=$1
    local entry=$2
    local tmp
    tmp=$(mktemp "$WORK/zip-magic.XXXXXX") ||
        die "$EXIT_BUILD" "cannot create a temporary file for the magic check"
    unzip -p "$archive" "$entry" > "$tmp" ||
        die "$EXIT_BUILD" "could not read $entry from the archive"
    od -An -tx1 -N4 "$tmp" | tr -d ' \n'
    rm -f -- "$tmp"
}

zip_is_executable() {
    local archive=$1
    local entry=$2
    local mode
    mode=$(unzip -Z -l "$archive" "$entry" 2>/dev/null | awk 'NR==1 { print $1 }')
    case "$mode" in
        *x*) return 0 ;;
        *) die "$EXIT_BUILD" "$entry in the archive has no executable bit (mode: ${mode:-unknown})" ;;
    esac
}

verify_archive() {
    local os=$1
    local archive=$2
    local list="$WORK/entries-$os.txt"

    [ -s "$archive" ] || die "$EXIT_BUILD" "archive is empty: $archive"
    unzip -tq "$archive" >/dev/null ||
        die "$EXIT_BUILD" "unzip -t failed (CRC or truncation): $archive"

    unzip -Z1 "$archive" > "$list" ||
        die "$EXIT_BUILD" "could not list the archive: $archive"
    zip_has_entry "$list" "Maps/DM-Rankin.ut2"

    case "$os" in
        windows)
            zip_has_entry "$list" "System/UT2004.exe"
            zip_has_entry "$list" "System/D3D11Drv.dll"
            zip_has_entry "$list" "System/Build.ini"
            case "$(zip_magic "$archive" "System/UT2004.exe")" in
                4d5a*) ;;
                *) die "$EXIT_BUILD" "archived System/UT2004.exe is not a PE binary" ;;
            esac
            zip_text_reports_3374 "$archive" "System/Build.ini"
            ;;
        linux)
            zip_has_entry "$list" "System/UT2004"
            zip_has_entry "$list" "System/ut2004-bin-amd64"
            zip_has_entry "$list" "System/Build.ini"
            [ "$(zip_magic "$archive" "System/UT2004")" = "7f454c46" ] ||
                die "$EXIT_BUILD" "archived System/UT2004 is not an ELF binary"
            zip_text_reports_3374 "$archive" "System/Build.ini"
            zip_is_executable "$archive" "System/UT2004"
            ;;
        macos)
            zip_has_entry "$list" "UT2004.app/Contents/MacOS/UT2004"
            zip_has_entry "$list" "UT2004.app/Contents/Resources/System/Packages.md5"
            zip_has_entry "$list" "Build.ini"
            case "$(zip_magic "$archive" "UT2004.app/Contents/MacOS/UT2004")" in
                cffaedfe|cefaedfe|cafebabe|bebafeca|feedfacf) ;;
                *) die "$EXIT_BUILD" "archived UT2004.app binary is not a Mach-O binary" ;;
            esac
            zip_text_reports_3374 "$archive" "Build.ini"
            zip_is_executable "$archive" "UT2004.app/Contents/MacOS/UT2004"
            ;;
        *)
            die "$EXIT_BUILD" "unknown OS: $os"
            ;;
    esac

    if command -v bsdtar >/dev/null 2>&1; then
        bsdtar -tf "$archive" >/dev/null ||
            die "$EXIT_BUILD" "bsdtar -tf rejected the archive: $archive"
    else
        warn "bsdtar not found; skipping the second archive listing check"
    fi
}

report_archive() {
    local archive=$1
    local size sha entries
    size=$(file_size "$archive")
    sha=$(sha256_of "$archive")
    entries=$(unzip -Z1 "$archive" | wc -l | tr -d ' ')
    printf '%-38s %12s bytes  %s  (%s entries)\n' \
        "${archive##*/}" "$size" "$sha" "$entries"
}

build_os() {
    local os=$1
    local tree="$WORK/tree-$os"
    local archive="$OUT_DIR/ut2004-portable-${PATCH_VERSION}-${os}.zip"

    LOG_PREFIX="[$os] "

    log "staging assets"
    rm -rf -- "$tree"
    mkdir -p "$tree" || die "$EXIT_BUILD" "cannot create $tree"
    cp -a "$SOURCE_TREE"/. "$tree"/ ||
        die "$EXIT_BUILD" "could not copy the source tree"

    [ -d "$tree/Maps" ] || die "$EXIT_BUILD" "copied tree has no Maps/"
    local stale
    for stale in System SystemLocalized SystemARM64 SystemPPC64LE; do
        rm -rf -- "${tree:?}/${stale:?}"
        [ ! -e "$tree/$stale" ] ||
            die "$EXIT_BUILD" "could not remove the source $stale/ before patching"
    done
    if [ "$os" = "macos" ] && [ -e "$tree/UT2004.app" ]; then
        rm -rf -- "$tree/UT2004.app"
    fi

    apply_patch "$os" "$tree"
    verify_tree "$os" "$tree"

    log "writing ${archive##*/}"
    rm -f -- "$archive"
    (
        cd "$tree" &&
            zip -q -r -X "$archive" . \
                -x '*.DS_Store' '__MACOSX/*' '._*' '*/._*' '.git/*' '*/.git/*'
    ) || die "$EXIT_BUILD" "zip failed: $archive"

    verify_archive "$os" "$archive"
    report_archive "$archive"
    log "ok"
    LOG_PREFIX=""
}

build_all() {
    if [ "$PARALLEL" -eq 1 ]; then
        local -a pids=()
        local os pid rc=0
        for os in windows linux macos; do
            build_os "$os" &
            pids+=("$!")
        done
        for pid in "${pids[@]}"; do
            if wait "$pid"; then
                :
            else
                rc=$?
            fi
        done
        [ "$rc" -eq 0 ] || exit "$rc"
    else
        build_os windows
        build_os linux
        build_os macos
    fi
}

print_plan() {
    local src_desc=$SOURCE
    local seven_desc

    if [ -d "$SOURCE" ]; then
        local resolved
        if resolved=$(resolve_tree "$SOURCE"); then
            src_desc="$resolved (flat tree)"
        else
            src_desc="$SOURCE (no flat tree found)"
        fi
    else
        src_desc="$SOURCE (ZIP, unpacked during the build)"
    fi

    if [ -n "${LANOMAT_7ZZ:-}" ]; then
        seven_desc="$LANOMAT_7ZZ (LANOMAT_7ZZ)"
    elif command -v 7zz >/dev/null 2>&1; then
        seven_desc="$(command -v 7zz)"
    else
        seven_desc="${XDG_DATA_HOME:-$HOME/.local/share}/lanomat/tools/7zz (download if missing)"
    fi

    cat <<EOF
Plan (dry run, nothing written):
  source:  $src_desc
  patches: $PATCH_DIR
           windows: ${WIN_PATCH##*/}
           linux:   ${LINUX_PATCH##*/}
           macos:   ${MAC_PATCH##*/}
  out:     $OUT_DIR
  work:    ${WORK_PARENT:-${TMPDIR:-/tmp}}/ut2004-portable.XXXXXX
  7zz:     $seven_desc
  archives:
           $OUT_DIR/ut2004-portable-${PATCH_VERSION}-windows.zip
           $OUT_DIR/ut2004-portable-${PATCH_VERSION}-linux.zip
           $OUT_DIR/ut2004-portable-${PATCH_VERSION}-macos.zip
  order:   copy assets -> remove System/ -> apply that OS's patch -> verify -> zip
EOF
}

main() {
    parse_args "$@"
    check_dependencies
    resolve_patches

    [ -e "$SOURCE" ] || die "$EXIT_INPUT" "source does not exist: $SOURCE"

    if [ "$DRY_RUN" -eq 1 ]; then
        print_plan
        return 0
    fi

    SOURCE=$(abspath "$SOURCE")
    PATCH_DIR=$(abspath "$PATCH_DIR")
    OUT_DIR=$(abspath "$OUT_DIR")
    if [ -n "$WORK_PARENT" ]; then
        WORK_PARENT=$(abspath "$WORK_PARENT")
    fi

    mkdir -p "$OUT_DIR" || die "$EXIT_INPUT" "cannot create output directory: $OUT_DIR"
    make_work_dir
    ensure_seven_zip
    prepare_source

    log "output: $OUT_DIR"
    build_all

    if [ "$KEEP" -eq 1 ]; then
        log "kept work directory: $WORK"
    fi
}

main "$@"
