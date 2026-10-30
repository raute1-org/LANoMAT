#!/usr/bin/env bash
# LANoMAT production backup: Postgres dump + storage volume tarball.
#
# Run as root from the compose project directory (default /opt/lanomat), e.g.
# via /etc/cron.d/lanomat-backup (see scripts/ops/install-backup-cron.sh).
#
# Exit codes: 0 ok | 1 database dump failed/invalid | 2 storage archive
# failed/invalid | 3 setup error (missing tool, unwritable backup dir).
# `.last-success` is only written when EVERYTHING succeeded; the app's
# Preflight BackupFreshnessCheck reads it.
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/lanomat}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
MIN_BYTES="${MIN_BYTES:-1024}"
STORAGE_VOLUME="${STORAGE_VOLUME:-lanomat_app_storage}"
COMPOSE=(docker compose --profile prod)

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
die() { local code=$1; shift; log "ERROR: $*"; exit "$code"; }

mkdir -p "$BACKUP_DIR" || die 3 "cannot create $BACKUP_DIR"
[ -w "$BACKUP_DIR" ] || die 3 "$BACKUP_DIR is not writable"

TMP="$(mktemp -d "$BACKUP_DIR/.tmp.XXXXXX")" || die 3 "cannot create temp dir"
trap 'rm -rf "$TMP"' EXIT

STAMP="$(date +%F_%H%M%S)"
DB_FILE="db-$STAMP.sql.gz"
ST_FILE="storage-$STAMP.tgz"

# verify <file> <label> <exitcode> [min_bytes]: valid gzip and, when
# min_bytes > 0, not suspiciously small. Defaults to MIN_BYTES; the storage
# archive passes 0 because an empty or tiny uploads volume is a valid state.
verify() {
  local file=$1 label=$2 code=$3 min=${4:-$MIN_BYTES} size
  gzip -t "$file" 2>/dev/null || die "$code" "$label is not a valid gzip archive"
  size=$(stat -c %s "$file")
  if [ "$min" -gt 0 ]; then
    [ "$size" -gt "$min" ] || die "$code" "$label is only $size bytes (<= $min), treating as failed"
  fi
  log "$label verified ($size bytes)"
}

log "Dumping database"
# pipefail makes a failing pg_dump fail the whole pipeline.
"${COMPOSE[@]}" exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
  | gzip > "$TMP/$DB_FILE" \
  || die 1 "pg_dump failed"
verify "$TMP/$DB_FILE" "database dump" 1

log "Archiving storage volume $STORAGE_VOLUME"
docker run --rm -v "$STORAGE_VOLUME":/data:ro alpine tar czf - -C /data . > "$TMP/$ST_FILE" \
  || die 2 "storage archive failed"
verify "$TMP/$ST_FILE" "storage archive" 2 0
tar tzf "$TMP/$ST_FILE" >/dev/null 2>&1 || die 2 "storage archive is not a readable tar"

# Atomic publish: same filesystem (temp dir lives inside BACKUP_DIR).
mv "$TMP/$DB_FILE" "$BACKUP_DIR/$DB_FILE"
mv "$TMP/$ST_FILE" "$BACKUP_DIR/$ST_FILE"

log "Pruning backups older than $RETENTION_DAYS days"
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*.sql.gz' -o -name 'storage-*.tgz' \) \
  -mtime +"$RETENTION_DAYS" -print -delete

date +%s > "$BACKUP_DIR/.last-success.tmp"
mv "$BACKUP_DIR/.last-success.tmp" "$BACKUP_DIR/.last-success"
log "Backup complete: $DB_FILE, $ST_FILE"
