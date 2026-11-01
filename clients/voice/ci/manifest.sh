#!/usr/bin/env bash
# Writes SHA256SUMS, the merged `manifest.json` and per-platform fragments for the release files in
# a directory, in the shape the server validates (app/Modules/WebVoice/Support/ClientBuilds.php) and
# scripts/ops/build-client.sh writes:
#
#   manifest.sh <dir> <app_version> <commit> <api_version> <windows_signed:true|false>
#
#   manifest.json  { app_version, commit, api_version, built_at,
#                    files: [ { name, os, arch, kind: app|cli, variant, size, sha256, signed } ] }
#   manifest-<os>-<arch>.json   the same document restricted to one platform
#   SHA256SUMS
#
# os, arch and kind come from the file name, which has to be
#   lanomat-voice-(app|cli)-<version>-(linux|windows|macos)-(x86_64|aarch64)[-setup|-portable].<extension>
# `variant` comes from the optional suffix: `-setup` is the installer, `-portable` the single portable
# exe, no suffix is a plain `archive` (tarballs, the CLI .exe).
# `signed` is true only for Windows files and only when the pipeline signed them. The extra field is
# ignored by the server today; the download page may show it later.
set -euo pipefail

dir=${1:?directory}
version=${2:?app_version}
commit=${3:?commit}
api=${4:?api_version}
win_signed=${5:?windows_signed}

cd "$dir"
shopt -s nullglob
files=()
for f in lanomat-voice-*; do
  [ -f "$f" ] && files+=("$f")
done
[ "${#files[@]}" -gt 0 ] || {
  echo "no release files in $dir" >&2
  exit 1
}

sha256sum -- "${files[@]}" >SHA256SUMS

built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
entries=()
for f in "${files[@]}"; do
  base=${f%.tar.gz}
  base=${base%.zip}
  base=${base%.exe}
  if [[ ! $base =~ ^lanomat-voice-(app|cli)-.+-(linux|windows|macos)-(x86_64|aarch64)(-setup|-portable)?$ ]]; then
    echo "unexpected file name: $f" >&2
    exit 1
  fi
  kind=${BASH_REMATCH[1]}
  os=${BASH_REMATCH[2]}
  arch=${BASH_REMATCH[3]}
  case ${BASH_REMATCH[4]} in
    -setup) variant=installer ;;
    -portable) variant=portable ;;
    *) variant=archive ;;
  esac
  if [ "$variant" != archive ] && { [ "$os" != windows ] || [ "$kind" != app ] || [[ $f != *.exe ]]; }; then
    echo "only the Windows app comes as setup/portable .exe: $f" >&2
    exit 1
  fi
  signed=false
  [ "$os" = windows ] && signed=$win_signed
  size=$(stat -c %s -- "$f")
  sum=$(sha256sum -- "$f" | cut -d' ' -f1)
  entries+=("$(jq -n --arg name "$f" --arg os "$os" --arg arch "$arch" --arg kind "$kind" --arg variant "$variant" \
    --argjson size "$size" --arg sha "$sum" --argjson signed "$signed" \
    '{name: $name, os: $os, arch: $arch, kind: $kind, variant: $variant, size: $size, sha256: $sha, signed: $signed}')")
done

all=$(printf '%s\n' "${entries[@]}" | jq -s .)
doc() { # doc <jq filter on the files array>
  jq -n --arg v "$version" --arg c "$commit" --argjson a "$api" --arg t "$built_at" \
    --argjson files "$all" "{app_version: \$v, commit: \$c, api_version: \$a, built_at: \$t, files: (\$files | $1)}"
}

doc '.' >manifest.json
for platform in $(jq -r '.[] | "\(.os)-\(.arch)"' <<<"$all" | sort -u); do
  os=${platform%%-*}
  arch=${platform#*-}
  doc "map(select(.os == \"$os\" and .arch == \"$arch\"))" >"manifest-$platform.json"
done

cat SHA256SUMS
cat manifest.json
