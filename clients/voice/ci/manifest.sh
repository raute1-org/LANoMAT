#!/usr/bin/env bash
# Writes SHA256SUMS and the per-platform manifest fragment for the release files in a directory.
#
#   manifest.sh <dir> <os> <arch> <app_version> <commit> <signed:true|false>
#
# Every regular file in <dir> that is not itself a checksum or manifest file is listed, in the shape
# the download page expects (see docs/infra/native-client-release.md):
#   { "app_version", "commit", "files": [ { name, os, arch, kind: app|cli, size, sha256, signed } ] }
# A file whose name starts with `lanomat-voice-app` is kind `app`, everything else is `cli`.
set -euo pipefail

dir=${1:?directory}
os=${2:?os}
arch=${3:?arch}
version=${4:?app_version}
commit=${5:?commit}
signed=${6:?signed}

cd "$dir"
shopt -s nullglob
files=()
for f in *; do
  case "$f" in
    SHA256SUMS | manifest-*.json) continue ;;
  esac
  [ -f "$f" ] && files+=("$f")
done
[ "${#files[@]}" -gt 0 ] || {
  echo "no release files in $dir" >&2
  exit 1
}

sha256sum -- "${files[@]}" >SHA256SUMS

entries=()
for f in "${files[@]}"; do
  kind=cli
  case "$f" in lanomat-voice-app*) kind=app ;; esac
  size=$(stat -c %s -- "$f")
  sum=$(sha256sum -- "$f" | cut -d' ' -f1)
  entries+=("$(jq -n --arg name "$f" --arg os "$os" --arg arch "$arch" --arg kind "$kind" \
    --argjson size "$size" --arg sha "$sum" --argjson signed "$signed" \
    '{name: $name, os: $os, arch: $arch, kind: $kind, size: $size, sha256: $sha, signed: $signed}')")
done

printf '%s\n' "${entries[@]}" | jq -s --arg v "$version" --arg c "$commit" \
  '{app_version: $v, commit: $c, files: .}' >"manifest-$os-$arch.json"

cat SHA256SUMS
cat "manifest-$os-$arch.json"
