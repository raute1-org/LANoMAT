#!/usr/bin/env bash
# Extract UT2004 source archives on Linux without root and without Wine.
#
# Handles the shapes the launcher actually meets:
#   * ZIP                          - bsdtar, unzip or 7zz
#   * tar.bz2 / tar.gz / tar.xz    - bsdtar, tar or 7zz
#   * WinRAR SFX (.exe, "Rar!")    - 7zz l / 7zz x
#   * Apple DMG (UT2004.app)       - 7zz x (Dmg -> HFS)
#   * ISO 9660 (retail disc)       - bsdtar or 7zz
#   * 7z / rar                     - 7zz
#
# 7-Zip standalone ("7zz") is cached at
#   ${LANOMAT_UT2004_TOOLS_DIR:-$HOME/.local/share/lanomat/tools}/7zz
# and downloaded from 7-zip.org on first use, with a pinned SHA-256. The
# 25.01 asset is primary; 24.09 is the fallback. Set LANOMAT_UT2004_7ZZ to an
# existing binary to stay offline.
#
# Usage:
#   extract-archive.sh list <archive> [--only <glob>]
#   extract-archive.sh tree <archive> [--depth N] [--only <glob>]
#   extract-archive.sh extract <archive> <dest> [--strip-wrapper] [--only <glob>]
#   extract-archive.sh ensure-7zz
#   extract-archive.sh --help
#
# Exit codes:
#   0 ok (possibly with warnings)
#   1 extraction or listing failed
#   2 usage / bad destination
#   3 7-Zip download or verification failed
#   4 archive is not a supported archive (e.g. an installer with no payload)
#   5 extraction worked but no UT2004 game data was found
#
# No root, no Wine, no pkill, no secrets. Paths with spaces and "+" are kept
# as separate arguments throughout.
set -euo pipefail

PROG="$(basename "${BASH_SOURCE[0]}")"
TOOLS_DIR="${LANOMAT_UT2004_TOOLS_DIR:-${XDG_DATA_HOME:-$HOME/.local/share}/lanomat/tools}"
SEVEN_ZIP="${LANOMAT_UT2004_7ZZ:-$TOOLS_DIR/7zz}"

# Pinned 7-Zip releases. SHA-256 values are the ones published for the assets
# (GitHub release digest for 25.01; the 24.09 asset predates that field, so its
# digest is pinned here from the release asset itself).
SEVEN_ZIP_2501_URL="https://www.7-zip.org/a/7z2501-linux-x64.tar.xz"
SEVEN_ZIP_2501_SHA="4ca3b7c6f2f67866b92622818b58233dc70367be2f36b498eb0bdeaaa44b53f4"
SEVEN_ZIP_2501_VER="25.01"
SEVEN_ZIP_2409_URL="https://github.com/ip7z/7zip/releases/download/24.09/7z2409-linux-x64.tar.xz"
SEVEN_ZIP_2409_SHA="914c7e20ad5ef8e4d3cf08620ff8894b28fe11b7eb99809d6930870fbe48a281"
SEVEN_ZIP_2409_VER="24.09"

MARKERS=0
TMP_DIRS=()
TMP_FILES=()

log()  { printf '==> %s\n' "$*" >&2; }
warn() { printf 'warning: %s\n' "$*" >&2; }
die()  { local code=$1; shift; printf 'error: %s\n' "$*" >&2; exit "$code"; }

cleanup() {
  local path
  if [ "${#TMP_DIRS[@]}" -gt 0 ]; then
    for path in "${TMP_DIRS[@]}"; do
      rm -rf -- "$path"
    done
  fi
  if [ "${#TMP_FILES[@]}" -gt 0 ]; then
    for path in "${TMP_FILES[@]}"; do
      rm -f -- "$path"
    done
  fi
  return 0
}
trap cleanup EXIT

new_tmp_file() {
  local file
  file="$(mktemp "${TMPDIR:-/tmp}/ut2004-extract.XXXXXX")" \
    || die 1 "cannot create a temporary file"
  TMP_FILES+=("$file")
  printf '%s\n' "$file"
}

usage() {
  cat <<'EOF'
Extract UT2004 sources on Linux - no root, no Wine.

Usage:
  extract-archive.sh list <archive> [--only <glob>]
  extract-archive.sh tree <archive> [--depth N] [--only <glob>]
  extract-archive.sh extract <archive> <dest> [--strip-wrapper] [--only <glob>]
  extract-archive.sh ensure-7zz
  extract-archive.sh --help

Commands:
  list          Print the archive entries (one path per line).
  tree          Print an indented tree (DMG: reads the HFS payload directly).
  extract       Extract into <dest> and run UT2004 sanity checks.
  ensure-7zz    Download/verify the cached 7zz and print its version.

Options:
  --only <glob>     Only paths matching the glob. Repeatable. Extraction
                    delegates the glob to the archive tool; list/tree match it
                    per path. Quote it to keep the shell from expanding it.
  --strip-wrapper   After extraction, remove a single wrapper directory
                    (e.g. "Unreal Tournament 2004/", "UT2004/", or the DMG
                    volume folder) and move its payload up into <dest>.
                    Requires an empty <dest>.
  --depth N         tree: maximum path components to print (default 3, 0 = all).
  -h, --help        This help.

7-Zip:
  Cached at ${LANOMAT_UT2004_TOOLS_DIR:-$HOME/.local/share/lanomat/tools}/7zz.
  Downloads 7z2501-linux-x64.tar.xz, SHA-256 pinned; falls back to 7z2409.
  Override with LANOMAT_UT2004_7ZZ=/path/to/7zz (e.g. offline).

Formats:
  zip, tar.bz2, tar.gz, tar.xz, 7z, rar, WinRAR SFX (.exe), Apple DMG, ISO.

Exit codes:
  0 ok | 1 extraction failed | 2 usage | 3 7-Zip download failed
  4 unsupported archive | 5 extracted but no UT2004 data found

Examples:
  extract-archive.sh list "Unreal Tournament 2004 nosTEAM.exe"
  extract-archive.sh tree "ut2k4+keygen.zip" --depth 2
  extract-archive.sh extract "ut2k4+keygen.zip" /tmp/ut2k4
  extract-archive.sh extract /tmp/ut2k4/setup/game.iso /tmp/ut2k4/game
  extract-archive.sh extract OldUnreal-UT2004Patch3374-macOS.dmg /tmp/ut2k4-mac --strip-wrapper
  extract-archive.sh extract OldUnreal-UT2004Patch3374-Linux.tar.bz2 /tmp/ut2k4 --strip-wrapper
  extract-archive.sh extract UT2004-3369.zip /tmp/ut2k4 --only 'UT2004/Maps/*' --only 'UT2004/Textures/*'
EOF
}

need_file() {
  local file=$1
  [ -e "$file" ] || die 2 "no such archive: $file"
  [ -f "$file" ] || die 2 "not a regular file: $file"
  [ -r "$file" ] || die 2 "archive is not readable: $file"
}

file_size() { stat -c %s -- "$1"; }

human_size() {
  local bytes=$1
  awk -v b="$bytes" 'BEGIN {
    split("B KiB MiB GiB TiB", unit, " ");
    i = 1;
    while (b >= 1024 && i < 5) { b /= 1024; i++ }
    if (i == 1) printf "%d %s", b, unit[i];
    else printf "%.1f %s", b, unit[i];
  }'
}

fetch() {
  local url=$1 dest=$2
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL --retry 3 --retry-delay 2 --connect-timeout 15 -o "$dest" "$url"
  elif command -v wget >/dev/null 2>&1; then
    wget -q -O "$dest" "$url"
  else
    die 3 "need curl or wget to download 7-Zip"
  fi
}

seven_zip_version() {
  local binary=$1 out line
  out="$("$binary" 2>&1 || true)"
  # 7zz without arguments prints a blank line before the version banner.
  while IFS= read -r line; do
    if [ -n "$line" ]; then
      printf '%s\n' "$line"
      return 0
    fi
  done <<<"$out"
  return 0
}

seven_zip_works() {
  local binary=$1
  [ -x "$binary" ] && seven_zip_version "$binary" | grep -q '^7-Zip'
}

extract_tar_xz() {
  local archive=$1 dir=$2
  if tar -xJf "$archive" -C "$dir" 7zz 2>/dev/null; then
    return 0
  fi
  if command -v bsdtar >/dev/null 2>&1 && bsdtar -xJf "$archive" -C "$dir" 7zz 2>/dev/null; then
    return 0
  fi
  if command -v xz >/dev/null 2>&1; then
    xz -dc -- "$archive" | tar -xf - -C "$dir" 7zz
  else
    return 1
  fi
}

ensure_7zz() {
  if seven_zip_works "$SEVEN_ZIP"; then
    return 0
  fi
  if [ -n "${LANOMAT_UT2004_7ZZ:-}" ]; then
    die 3 "LANOMAT_UT2004_7ZZ=$SEVEN_ZIP is not a working 7zz"
  fi

  mkdir -p -- "$TOOLS_DIR" || die 3 "cannot create $TOOLS_DIR"
  [ -w "$TOOLS_DIR" ] || die 3 "$TOOLS_DIR is not writable"

  # Serialise concurrent downloads. flock is optional; mkdir-style locking is
  # not worth the complexity for a single small asset.
  if command -v flock >/dev/null 2>&1; then
    exec 9>"$TOOLS_DIR/.7zz.lock"
    flock 9
  fi

  # Another process may have installed it while we waited.
  if seven_zip_works "$SEVEN_ZIP"; then
    return 0
  fi

  local work tar name url sha label
  # Keep the download next to the installed binary: /tmp may be a small tmpfs.
  work="$(mktemp -d "$TOOLS_DIR/.7zz.XXXXXX")" \
    || die 3 "cannot create a temporary directory in $TOOLS_DIR"
  TMP_DIRS+=("$work")
  tar="$work/7zz.tar.xz"

  local spec
  for spec in \
    "${SEVEN_ZIP_2501_URL}|${SEVEN_ZIP_2501_SHA}|${SEVEN_ZIP_2501_VER}" \
    "${SEVEN_ZIP_2409_URL}|${SEVEN_ZIP_2409_SHA}|${SEVEN_ZIP_2409_VER}"
  do
    IFS='|' read -r url sha label <<<"$spec"
    name="$work/7zz-$label"
    rm -rf -- "$name"
    mkdir -p -- "$name"

    log "downloading 7-Zip $label (one-time, ~1.5 MiB)"
    if ! fetch "$url" "$tar"; then
      warn "download failed: $url"
      continue
    fi

    local got
    got="$(sha256sum -- "$tar" | awk '{print $1}')"
    if [ "$got" != "$sha" ]; then
      warn "SHA-256 mismatch for 7-Zip $label (expected $sha, got $got)"
      rm -f -- "$tar"
      continue
    fi

    if ! extract_tar_xz "$tar" "$name"; then
      warn "cannot unpack $url (need xz support)"
      rm -f -- "$tar"
      continue
    fi
    if [ ! -s "$name/7zz" ]; then
      warn "archive $url does not contain 7zz"
      rm -f -- "$tar"
      continue
    fi

    install -m 0755 -- "$name/7zz" "$SEVEN_ZIP" || die 3 "cannot install $SEVEN_ZIP"
    if ! seven_zip_version "$SEVEN_ZIP" | grep -Fq "$label"; then
      warn "installed 7zz did not report version $label"
      rm -f -- "$SEVEN_ZIP"
      rm -f -- "$tar"
      continue
    fi

    log "installed 7-Zip $label at $SEVEN_ZIP"
    rm -f -- "$tar"
    return 0
  done

  if command -v 7zz >/dev/null 2>&1 && seven_zip_works "$(command -v 7zz)"; then
    SEVEN_ZIP="$(command -v 7zz)"
    warn "download failed; using system 7zz at $SEVEN_ZIP"
    return 0
  fi

  die 3 "could not download or verify 7zz; set LANOMAT_UT2004_7ZZ to an existing binary"
}

# --- format detection -------------------------------------------------------

magic_hex() {
  od -An -tx1 -N"${2:-8}" -- "$1" | tr -d ' \n'
}

is_iso() {
  local file=$1
  [ "$(dd if="$file" bs=1 skip=32769 count=5 status=none 2>/dev/null || true)" = "CD001" ]
}

is_dmg() {
  local file=$1 size
  size="$(file_size "$file")"
  [ "$size" -ge 512 ] || return 1
  [ "$(dd if="$file" bs=1 skip=$((size - 512)) count=4 status=none 2>/dev/null || true)" = "koly" ]
}

# Echo one of: zip tarbz2 targz tarxz sevenzip rar sfx pe dmg iso unknown
detect_format() {
  local file=$1 magic
  magic="$(magic_hex "$file" 8)"

  case "$magic" in
    504b0304*|504b0506*|504b0708*) printf 'zip\n'; return 0 ;;
    377abcaf271c*)                 printf 'sevenzip\n'; return 0 ;;
    526172211a07*)                 printf 'rar\n'; return 0 ;;
    425a68*)                       printf 'tarbz2\n'; return 0 ;;
    1f8b*)                         printf 'targz\n'; return 0 ;;
    fd377a585a00*)                 printf 'tarxz\n'; return 0 ;;
    4d5a*)
      # PE. WinRAR SFX prefixes a real RAR stream with a Windows stub; the
      # signature sits within the first few MiB. 7zz opens both RAR4/RAR5 SFX
      # directly, with or without the stub.
      if head -c 8388608 -- "$file" | LC_ALL=C grep -aq 'Rar!'; then
        printf 'sfx\n'
      else
        printf 'pe\n'
      fi
      return 0
      ;;
  esac

  if is_dmg "$file"; then printf 'dmg\n'; return 0; fi
  if is_iso "$file"; then printf 'iso\n'; return 0; fi

  # Last resort: trust a well-known suffix, but say so.
  case "${file,,}" in
    *.zip) printf 'zip\n'; return 0 ;;
    *.tar.bz2|*.tbz2|*.tbz) printf 'tarbz2\n'; return 0 ;;
    *.tar.gz|*.tgz) printf 'targz\n'; return 0 ;;
    *.tar.xz|*.txz) printf 'tarxz\n'; return 0 ;;
    *.7z) printf 'sevenzip\n'; return 0 ;;
    *.rar) printf 'rar\n'; return 0 ;;
    *.dmg) printf 'dmg\n'; return 0 ;;
    *.iso) printf 'iso\n'; return 0 ;;
  esac

  printf 'unknown\n'
}

format_label() {
  case "$1" in
    zip)      printf 'ZIP' ;;
    tarbz2)   printf 'tar.bz2' ;;
    targz)    printf 'tar.gz' ;;
    tarxz)    printf 'tar.xz' ;;
    sevenzip) printf '7z' ;;
    rar)      printf 'RAR' ;;
    sfx)      printf 'WinRAR SFX (PE stub + Rar!)' ;;
    pe)       printf 'PE installer (no archive signature)' ;;
    dmg)      printf 'Apple DMG (7zz Dmg/HFS)' ;;
    iso)      printf 'ISO 9660' ;;
    *)        printf 'unknown' ;;
  esac
}

# --- listing ----------------------------------------------------------------

list_with_7zz() {
  local archive=$1 real
  real="$(realpath -- "$archive" 2>/dev/null || printf '%s' "$archive")"
  "$SEVEN_ZIP" l -slt -- "$archive" 2>/dev/null | awk -v a="$archive" -v ra="$real" '
    /^----------$/ { block = 1; next }
    block && /^Path = / {
      p = substr($0, 8)
      if (p != a && p != ra) print p
    }
  '
}

list_with_bsdtar() {
  bsdtar -tf "$1" 2>/dev/null
}

list_with_tar() {
  local archive=$1
  case "$2" in
    tarbz2) tar -tjf "$archive" 2>/dev/null ;;
    targz)  tar -tzf "$archive" 2>/dev/null ;;
    tarxz)  tar -tJf "$archive" 2>/dev/null ;;
    *)      return 1 ;;
  esac
}

list_with_unzip() {
  unzip -Z1 "$1" 2>/dev/null
}

list_raw() {
  local archive=$1 format=$2
  case "$format" in
    zip)
      if command -v bsdtar >/dev/null 2>&1; then list_with_bsdtar "$archive" && return 0; fi
      if command -v unzip >/dev/null 2>&1; then list_with_unzip "$archive" && return 0; fi
      ensure_7zz
      list_with_7zz "$archive"
      ;;
    tarbz2|targz|tarxz)
      if command -v bsdtar >/dev/null 2>&1; then list_with_bsdtar "$archive" && return 0; fi
      if list_with_tar "$archive" "$format"; then return 0; fi
      ensure_7zz
      list_with_7zz "$archive"
      ;;
    iso)
      if command -v bsdtar >/dev/null 2>&1; then list_with_bsdtar "$archive" && return 0; fi
      ensure_7zz
      list_with_7zz "$archive"
      ;;
    sevenzip|rar|sfx|dmg)
      ensure_7zz
      list_with_7zz "$archive"
      ;;
    pe)
      ensure_7zz
      list_with_7zz "$archive"
      ;;
    *)
      if command -v bsdtar >/dev/null 2>&1 && list_with_bsdtar "$archive"; then return 0; fi
      ensure_7zz
      list_with_7zz "$archive"
      ;;
  esac
}

# Strip leading "./" and drop the "." root entry, for display and globbing.
normalise_path() {
  local path=$1
  while [ "${path#./}" != "$path" ]; do path="${path#./}"; done
  [ "$path" = "." ] && path=""
  printf '%s' "$path"
}

path_matches() {
  local path=$1
  shift
  [ "$#" -eq 0 ] && return 0
  local pattern
  for pattern in "$@"; do
    # Unquoted $pattern on purpose: [[ ]] performs glob matching.
    # shellcheck disable=SC2053
    [[ $path == $pattern ]] && return 0
  done
  return 1
}

load_entries() {
  local archive=$1 format=$2
  local -n out=$3
  local raw path
  local tmp
  tmp="$(new_tmp_file)"
  if ! list_raw "$archive" "$format" >"$tmp"; then
    die 1 "could not list $archive as $format"
  fi
  out=()
  while IFS= read -r raw; do
    path="$(normalise_path "$raw")"
    [ -n "$path" ] && out+=("$path")
  done <"$tmp"
  if [ "${#out[@]}" -eq 0 ]; then
    die 1 "no entries listed in $archive (is it truncated or password protected?)"
  fi
}

# --- extraction -------------------------------------------------------------

extract_with_7zz() {
  local archive=$1 dest=$2
  shift 2
  local -a includes=()
  local pattern
  for pattern in "$@"; do
    includes+=("-i!$pattern")
  done
  ensure_7zz
  "$SEVEN_ZIP" x -y -o"$dest" "${includes[@]}" -- "$archive"
}

bsdtar_include_args() {
  # tar stores "./Maps/x" in some archives and "Maps/x" in others; accept both.
  local pattern
  for pattern in "$@"; do
    printf '%s\0' "$pattern"
    case "$pattern" in
      ./*) ;;
      *) printf '%s\0' "./$pattern" ;;
    esac
  done
}

extract_with_bsdtar() {
  local archive=$1 dest=$2
  shift 2
  local -a patterns=()
  local pattern
  while IFS= read -r -d '' pattern; do
    patterns+=("$pattern")
  done < <(bsdtar_include_args "$@")
  bsdtar -x -f "$archive" -C "$dest" -- "${patterns[@]}"
}

extract_with_unzip() {
  local archive=$1 dest=$2
  shift 2
  local -a patterns=()
  local pattern
  while IFS= read -r -d '' pattern; do
    patterns+=("$pattern")
  done < <(bsdtar_include_args "$@")
  unzip -q -o "$archive" -d "$dest" "${patterns[@]}"
}

extract_with_tar() {
  local archive=$1 dest=$2 format=$3
  shift 3
  local -a patterns=()
  local pattern
  while IFS= read -r -d '' pattern; do
    patterns+=("$pattern")
  done < <(bsdtar_include_args "$@")

  local -a opts=()
  case "$format" in
    tarbz2) opts=(-xjf) ;;
    targz)  opts=(-xzf) ;;
    tarxz)  opts=(-xJf) ;;
  esac
  tar "${opts[@]}" "$archive" -C "$dest" --wildcards -- "${patterns[@]}"
}

strip_wrapper() {
  local dest=$1
  local -a entries=() real=()
  local entry name
  while IFS= read -r -d '' entry; do
    entries+=("$entry")
  done < <(find "$dest" -mindepth 1 -maxdepth 1 -print0)

  for entry in "${entries[@]}"; do
    name="$(basename -- "$entry")"
    case "$name" in
      .DS_Store|.background|.VolumeIcon.icns|.fseventsd|.Trashes|\
      '[HFS+ Private Data]'|'.HFS+ Private Directory Data')
        rm -rf -- "$entry"
        ;;
      *) real+=("$entry") ;;
    esac
  done

  if [ "${#real[@]}" -eq 0 ]; then
    warn "--strip-wrapper: destination is empty"
    return 0
  fi
  if [ "${#real[@]}" -ne 1 ]; then
    warn "--strip-wrapper: expected one top-level entry, found ${#real[@]}; leaving it alone"
    return 0
  fi

  local wrapper="${real[0]}"
  if [ -L "$wrapper" ] || [ ! -d "$wrapper" ]; then
    warn "--strip-wrapper: the only top-level entry is not a directory; leaving it alone"
    return 0
  fi

  name="$(basename -- "$wrapper")"
  case "${name,,}" in
    maps|textures|system|sounds|animations|staticmeshes|karmadata|help|music|prefabs|speech|web|ut2004.app)
      log "--strip-wrapper: $name is already the payload root"
      return 0
      ;;
  esac

  shopt -s dotglob nullglob
  local -a payload=("$wrapper"/*)
  shopt -u dotglob nullglob
  if [ "${#payload[@]}" -eq 0 ]; then
    log "--strip-wrapper: removing empty wrapper $name"
    rmdir -- "$wrapper"
    return 0
  fi

  mv -f -- "${payload[@]}" "$dest"/
  rmdir -- "$wrapper"
  log "--strip-wrapper: moved payload out of $name/"

  # macOS volume junk that is useless after extraction.
  rm -rf -- "$dest/[HFS+ Private Data]" "$dest/.HFS+ Private Directory Data"
  rm -f -- "$dest/.DS_Store" "$dest/.VolumeIcon.icns"
  rm -rf -- "$dest/.background"
  if [ -L "$dest/Applications" ] && [ "$(readlink -- "$dest/Applications")" = "/Applications" ]; then
    rm -f -- "$dest/Applications"
  fi
}

report_check() {
  local root=$1 label=$2
  shift 2
  local hit
  hit="$(find "$root" "$@" -print -quit 2>/dev/null || true)"
  if [ -n "$hit" ]; then
    MARKERS=$((MARKERS + 1))
    printf '  ok   %-26s %s\n' "$label" "${hit#"$root"/}" >&2
  else
    printf '  --   %-26s missing\n' "$label" >&2
  fi
}

sanity_check() {
  local dest=$1 partial=$2 archive=$3
  local files size
  files="$(find "$dest" -type f -printf '.' 2>/dev/null | wc -c)"
  size="$(du -sh -- "$dest" 2>/dev/null | cut -f1)"

  log "sanity: $files file(s), $size in $dest"
  MARKERS=0

  report_check "$dest" "Maps/DM-Rankin.ut2" -name 'DM-Rankin.ut2' -type f
  report_check "$dest" "any map (*.ut2)" -name '*.ut2' -type f
  report_check "$dest" "textures (*.utx)" -name '*.utx' -type f
  report_check "$dest" "System/Build.ini" -name 'Build.ini' -type f
  report_check "$dest" "UT2004.app binary" -path '*/UT2004.app/Contents/MacOS/UT2004' -type f
  report_check "$dest" "Linux binary" -name 'ut2004-bin*' -type f
  report_check "$dest" "Windows binary" -name 'UT2004.exe' -type f

  if [ "$files" -eq 0 ]; then
    die 5 "extracted nothing into $dest"
  fi

  if [ "$MARKERS" -gt 0 ]; then
    log "sanity: UT2004 markers found ($MARKERS check(s) passed)"
    return 0
  fi

  local installers iso first_iso top
  installers="$(find "$dest" -maxdepth 3 -type f \
    \( -iname 'setup.exe' -o -iname 'install.exe' -o -iname '*.msi' \) \
    -print -quit 2>/dev/null || true)"
  iso="$(find "$dest" -type f -iname '*.iso' -print -quit 2>/dev/null || true)"
  top="$(find "$dest" -mindepth 1 -maxdepth 1 -printf '  %f\n' 2>/dev/null | head -n 10)"

  if [ "$partial" -eq 1 ]; then
    warn "no UT2004 markers in the partial extraction (expected with --only)"
    return 0
  fi

  printf '%s\n' "extracted top-level entries:" >&2
  printf '%s\n' "${top:-  (none)}" >&2

  if [ -n "$installers" ] && [ -n "$iso" ]; then
    first_iso="$(find "$dest" -type f -iname '*.iso' -printf '%p\n' 2>/dev/null | head -n 1)"
    printf '%s\n' \
      "error: this archive is an installer wrapper, not a game tree." \
      "       It contains a Windows installer and a nested ISO image:" \
      "         installer: ${installers#"$dest"/}" \
      "         image:     ${first_iso#"$dest"/}" \
      "       No Wine and no root are used here. Extract the image, then rerun:" \
      "         $PROG extract \"$first_iso\" \"$dest/game\" --strip-wrapper" >&2
    exit 5
  fi

  if [ -n "$installers" ]; then
    printf '%s\n' \
      "error: only a Windows installer was extracted (${installers#"$dest"/})." \
      "       This is a setup.exe/Inno/NSIS wrapper, not a UT2004 tree." \
      "       No Wine and no root are used here. Use the retail ISO or a" \
      "       repack that is a real archive/SFX, e.g.:" \
      "         $PROG list \"Unreal Tournament 2004 nosTEAM.exe\"" >&2
    exit 5
  fi

  if [ -n "$iso" ]; then
    printf '%s\n' \
      "error: the archive contains an ISO image but no UT2004 tree." \
      "       Extract the image instead:" \
      "         $PROG extract \"${iso#"$dest"/}\" \"$dest/game\" --strip-wrapper" >&2
    exit 5
  fi

  printf '%s\n' \
    "error: no UT2004 markers found (Maps/*.ut2, Textures/*.utx, System/Build.ini," \
    "       UT2004.app/Contents/MacOS/UT2004, ut2004-bin*, UT2004.exe)." \
    "       The archive may be the wrong file, truncated, or an unknown installer." \
    "       Inspect it with: $PROG list \"$archive\"" >&2
  exit 5
}

extract_archive() {
  local archive=$1 dest=$2 format=$3
  shift 3

  log "extracting $(format_label "$format") -> $dest"

  case "$format" in
    zip)
      if command -v bsdtar >/dev/null 2>&1 && extract_with_bsdtar "$archive" "$dest" "$@"; then
        :
      elif command -v unzip >/dev/null 2>&1 && extract_with_unzip "$archive" "$dest" "$@"; then
        :
      else
        extract_with_7zz "$archive" "$dest" "$@" || die 1 "extraction failed: $archive"
      fi
      ;;
    tarbz2|targz|tarxz)
      if command -v bsdtar >/dev/null 2>&1 && extract_with_bsdtar "$archive" "$dest" "$@"; then
        :
      elif extract_with_tar "$archive" "$dest" "$format" "$@"; then
        :
      else
        extract_with_7zz "$archive" "$dest" "$@" || die 1 "extraction failed: $archive"
      fi
      ;;
    iso)
      if command -v bsdtar >/dev/null 2>&1 && extract_with_bsdtar "$archive" "$dest" "$@"; then
        :
      else
        extract_with_7zz "$archive" "$dest" "$@" || die 1 "extraction failed: $archive"
      fi
      ;;
    sevenzip|rar|sfx)
      extract_with_7zz "$archive" "$dest" "$@" || die 1 "extraction failed: $archive"
      ;;
    dmg)
      extract_with_7zz "$archive" "$dest" "$@" || die 1 "DMG extraction failed: $archive"
      ;;
    pe)
      ensure_7zz
      if ! "$SEVEN_ZIP" l -- "$archive" >/dev/null 2>&1; then
        die 4 "unsupported Windows installer: $archive (no RAR/7z/zip payload found; no Wine is used)"
      fi
      extract_with_7zz "$archive" "$dest" "$@" || die 1 "extraction failed: $archive"
      ;;
    unknown)
      if command -v bsdtar >/dev/null 2>&1 && extract_with_bsdtar "$archive" "$dest" "$@"; then
        :
      else
        extract_with_7zz "$archive" "$dest" "$@" || die 4 "unsupported archive format: $archive"
      fi
      ;;
    *)
      die 4 "unsupported archive format: $archive"
      ;;
  esac
}

# --- tree rendering ---------------------------------------------------------

render_tree() {
  local depth=$1
  awk -v max="$depth" -F/ '
    function indent(n,   s, j) {
      s = ""
      for (j = 1; j < n; j++) s = s "  "
      return s
    }
    {
      p = $0
      isdir = 0
      if (p ~ /\/$/) { isdir = 1; sub(/\/$/, "", p) }
      sub(/^\.\//, "", p)
      if (p == "" || p == ".") next
      n = split(p, part, "/")
      limit = n
      if (max > 0 && limit > max) limit = max
      pref = ""
      for (i = 1; i <= limit; i++) {
        pref = (i == 1) ? part[i] : pref "/" part[i]
        if (!(pref in seen)) {
          seen[pref] = 1
          suffix = (i < n) ? "/" : (isdir ? "/" : "")
          printf "%s%s%s\n", indent(i), part[i], suffix
        }
      }
    }
  '
}

tree_from_paths() {
  local depth=$1
  shift
  local -a paths=("$@")
  printf '%s\n' "${paths[@]}" | LC_ALL=C sort -u | render_tree "$depth"
}

# --- commands ---------------------------------------------------------------

parse_common() {
  # Sets ARCHIVE, ONLY[], DEPTH. Accepts --only and --depth; ignores the rest.
  ARCHIVE=""
  ONLY=()
  DEPTH=3
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --only)
        [ "$#" -ge 2 ] || die 2 "--only needs a glob"
        ONLY+=("$2")
        shift 2
        ;;
      --only=*)
        ONLY+=("${1#*=}")
        shift
        ;;
      --depth)
        [ "$#" -ge 2 ] || die 2 "--depth needs a number"
        DEPTH="$2"
        shift 2
        ;;
      --depth=*)
        DEPTH="${1#*=}"
        shift
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      --)
        shift
        while [ "$#" -gt 0 ]; do
          [ -z "$ARCHIVE" ] || die 2 "unexpected argument: $1"
          ARCHIVE="$1"
          shift
        done
        ;;
      -*)
        die 2 "unknown option: $1"
        ;;
      *)
        [ -z "$ARCHIVE" ] || die 2 "unexpected argument: $1"
        ARCHIVE="$1"
        shift
        ;;
    esac
  done
}

cmd_list() {
  parse_common "$@"
  [ -n "$ARCHIVE" ] || die 2 "list needs an archive"
  need_file "$ARCHIVE"

  local format
  format="$(detect_format "$ARCHIVE")"
  log "$(format_label "$format"), $(human_size "$(file_size "$ARCHIVE")"): $ARCHIVE"
  if [ "$format" = "pe" ]; then
    warn "PE file without a Rar!/7z/zip signature; 7zz may still open self-extractors"
  fi

  local -a entries=() filtered=()
  load_entries "$ARCHIVE" "$format" entries
  local entry
  for entry in "${entries[@]}"; do
    path_matches "$entry" "${ONLY[@]}" && filtered+=("$entry")
  done

  if [ "${#filtered[@]}" -eq 0 ]; then
    warn "no entries matched --only"
  else
    printf '%s\n' "${filtered[@]}"
  fi

  log "${#filtered[@]} of ${#entries[@]} entries"
  if [ "$format" = "dmg" ]; then
    log "note: 7zz reads the DMG's HFS payload directly; no hdiutil or root needed"
  fi
}

cmd_tree() {
  parse_common "$@"
  [ -n "$ARCHIVE" ] || die 2 "tree needs an archive"
  need_file "$ARCHIVE"
  [[ "$DEPTH" =~ ^[0-9]+$ ]] || die 2 "--depth must be a non-negative integer"

  local format
  format="$(detect_format "$ARCHIVE")"
  log "$(format_label "$format"): $ARCHIVE"

  local -a entries=() filtered=()
  local entry

  load_entries "$ARCHIVE" "$format" entries
  for entry in "${entries[@]}"; do
    path_matches "$entry" "${ONLY[@]}" && filtered+=("$entry")
  done
  if [ "${#filtered[@]}" -eq 0 ]; then
    warn "no entries matched --only"
    return 0
  fi
  tree_from_paths "$DEPTH" "${filtered[@]}"
}

cmd_extract() {
  ARCHIVE=""
  DEST=""
  STRIP=0
  ONLY=()
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --only)
        [ "$#" -ge 2 ] || die 2 "--only needs a glob"
        ONLY+=("$2")
        shift 2
        ;;
      --only=*)
        ONLY+=("${1#*=}")
        shift
        ;;
      --strip-wrapper)
        STRIP=1
        shift
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      --)
        shift
        while [ "$#" -gt 0 ]; do
          if [ -z "$ARCHIVE" ]; then ARCHIVE="$1"
          elif [ -z "$DEST" ]; then DEST="$1"
          else die 2 "unexpected argument: $1"
          fi
          shift
        done
        ;;
      -*)
        die 2 "unknown option: $1"
        ;;
      *)
        if [ -z "$ARCHIVE" ]; then ARCHIVE="$1"
        elif [ -z "$DEST" ]; then DEST="$1"
        else die 2 "unexpected argument: $1"
        fi
        shift
        ;;
    esac
  done

  [ -n "$ARCHIVE" ] || die 2 "extract needs an archive"
  [ -n "$DEST" ] || die 2 "extract needs a destination"
  need_file "$ARCHIVE"

  if [ -e "$DEST" ] && [ ! -d "$DEST" ]; then
    die 2 "destination exists and is not a directory: $DEST"
  fi
  if [ "$STRIP" -eq 1 ] && [ -d "$DEST" ] && [ -n "$(find "$DEST" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
    die 2 "--strip-wrapper requires an empty destination: $DEST"
  fi
  mkdir -p -- "$DEST" || die 2 "cannot create destination: $DEST"

  local format
  format="$(detect_format "$ARCHIVE")"
  log "$(format_label "$format"), $(human_size "$(file_size "$ARCHIVE")"): $ARCHIVE"

  extract_archive "$ARCHIVE" "$DEST" "$format" "${ONLY[@]}"

  if [ "$STRIP" -eq 1 ]; then
    strip_wrapper "$DEST"
  fi

  local partial=0
  if [ "${#ONLY[@]}" -gt 0 ]; then
    partial=1
  fi
  sanity_check "$DEST" "$partial" "$ARCHIVE"

  log "done: $DEST"
}

cmd_ensure_7zz() {
  ensure_7zz
  printf '%s\n' "$SEVEN_ZIP"
  seven_zip_version "$SEVEN_ZIP" >&2
}

main() {
  [ "$#" -gt 0 ] || { usage; exit 2; }
  local command=$1
  shift
  case "$command" in
    list)       cmd_list "$@" ;;
    tree)       cmd_tree "$@" ;;
    extract)    cmd_extract "$@" ;;
    ensure-7zz) cmd_ensure_7zz "$@" ;;
    -h|--help|help) usage ;;
    *)          die 2 "unknown command: $command (try --help)" ;;
  esac
}

main "$@"
