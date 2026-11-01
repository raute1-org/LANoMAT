#!/usr/bin/env bash
# Maps the release's version string to the NUMERIC x.y.z that Windows file/product version metadata
# and the NSIS installer need. The exact string stays everywhere else (file names, manifest,
# LIESMICH, `--version`); only the Windows version resource uses this mapping.
#
#   win-version.sh <version>          prints x.y.z
#   win-version.sh --selftest         checks the rule against fixed cases
#
# Rule (first match wins; every number is at most 65535, the limit of a Windows version field):
#   1. a release tag `client-v<A>.<B>.<C>` or a plain `v<A>.<B>.<C>` / `<A>.<B>.<C>`  ->  A.B.C
#   2. `git describe` output `<tag>-<N>-g<hex>` (optionally `-dirty`)                  ->  0.N.0
#      N is the number of commits since the tag: it grows with every commit on a branch,
#      so a newer build of the same line has a larger version.
#   3. anything else (a free-form override such as `dev`)                              ->  0.0.0
# Examples: m12-496-g9624daa -> 0.496.0, client-v1.2.3 -> 1.2.3, dev -> 0.0.0
set -euo pipefail

map() {
  local v=$1 a b c n
  if [[ $v =~ ^(client-)?v?([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    a=${BASH_REMATCH[2]} b=${BASH_REMATCH[3]} c=${BASH_REMATCH[4]}
  elif [[ $v =~ -([0-9]+)-g[0-9a-f]+(-dirty)?$ ]]; then
    a=0 b=${BASH_REMATCH[1]} c=0
  else
    a=0 b=0 c=0
  fi
  for n in "$a" "$b" "$c"; do
    # 10# stops bash from reading leading zeros as octal; the length check keeps it a plain number.
    if [ "${#n}" -gt 5 ] || [ $((10#$n)) -gt 65535 ]; then
      echo "win-version: '$v' does not fit Windows version fields (max 65535 each)" >&2
      return 1
    fi
  done
  printf '%d.%d.%d\n' "$((10#$a))" "$((10#$b))" "$((10#$c))"
}

if [ "${1:-}" = "--selftest" ]; then
  fail=0
  check() {
    local got
    got=$(map "$1" 2>/dev/null || echo error)
    if [ "$got" != "$2" ]; then
      echo "FAIL: $1 -> $got (expected $2)" >&2
      fail=1
    fi
  }
  check m12-496-g9624daa 0.496.0
  check m12-0-g9624daa 0.0.0
  check m12-496-g9624daa-dirty 0.496.0
  check client-v1.2.3 1.2.3
  check v0.10.7 0.10.7
  check 2.0.1 2.0.1
  check dev 0.0.0
  check client-v1.2.3-rc1 0.0.0
  check m12-70000-gabcdef0 error
  check client-v1.70000.3 error
  [ "$fail" = 0 ] && echo "win-version selftest passed"
  exit "$fail"
fi

map "${1:?version string}"
