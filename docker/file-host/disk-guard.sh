#!/usr/bin/env bash
# LANoMAT file host: free-space guard for resumable uploads.
#
# tusd has no notion of free disk space. This script (run from cron every
# minute, as root or the file account) creates <FILES_ROOT>/guard/disk-full
# while the data directory has less than MIN_FREE_GB left; the nginx upload
# location answers 507 to every NEW upload (POST) while the flag exists.
# Running uploads continue (they were admitted when there was room); the app's
# quota keeps a single user from taking all of it.
#
#   * * * * * root /usr/local/bin/lanomat-files-disk-guard <FILES_ROOT> 200
#
# Usage: disk-guard.sh <FILES_ROOT> [MIN_FREE_GB]    (default 100)
set -euo pipefail

root="${1:?usage: disk-guard.sh <FILES_ROOT> [MIN_FREE_GB]}"
min_free_gb="${2:-100}"

case "$min_free_gb" in '' | *[!0-9]*) echo "MIN_FREE_GB must be a number" >&2; exit 2 ;; esac
[ -d "$root/data" ] || { echo "no data directory below $root" >&2; exit 2; }

install -d -m 755 "$root/guard"
free_kb="$(df --output=avail -k "$root/data" | tail -n 1 | tr -d ' ')"
min_free_kb=$((min_free_gb * 1024 * 1024))

if [ "$free_kb" -lt "$min_free_kb" ]; then
  : >"$root/guard/disk-full"
else
  rm -f "$root/guard/disk-full"
fi
