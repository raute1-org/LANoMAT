#!/usr/bin/env bash
# LANoMAT restore drill: proves that the newest production backup can really be
# restored, WITHOUT touching production data and WITHOUT data leaving the host.
#
# Run as root ON the production server from the compose project directory
# (default /opt/lanomat):
#   ./scripts/ops/restore-drill.sh [--backup-dir DIR] [--dump FILE] [--keep]
#                                  [--allow-newer-prod]
#
# What it does (production is only ever READ):
#   1. pre-checks: marker age, gzip -t, free disk
#   2. scratch Postgres (same image as production, own network/volume, no ports)
#      and `psql` restore of the dump
#   3. compares production vs restored DB (tables, row counts, migrations,
#      sequences, constraints, indexes)
#   4. boots the CURRENT app image against the restored DB (migrate:status,
#      preflight, usability + APP_KEY decryption check). The scratch network is
#      `--internal`, all integrations are blanked and drivers are array/sync/log,
#      so the scratch app can neither reach external services nor production.
#   5. extracts the storage archive and compares it with the live volume
#      (mounted read-only)
# Only counts, sizes, table names and status lines are printed - never rows,
# tokens or the environment file.
#
# Scratch objects are all named `lanomat-drill-*` and removed on exit (also on
# failure) unless --keep is given.
#
# Exit codes: 0 ok | 1 restore failed | 2 verification mismatch | 3 setup error
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/lanomat}"
PROJECT_DIR="$(pwd)"
STORAGE_VOLUME="${STORAGE_VOLUME:-lanomat_app_storage}"
ENV_FILE="${ENV_FILE:-$PROJECT_DIR/.env}"
DUMP=""
KEEP=0
ALLOW_NEWER_PROD=0
NET="lanomat-drill"
DB_CT="lanomat-drill-db"
PGVOL="lanomat-drill-pgdata"
STVOL="lanomat-drill-storage"
# Tables that change continuously; a count delta there is expected drift.
DRIFT_RE='^([a-z_]+[.])?(sessions|cache|cache_locks|jobs|job_batches|failed_jobs|notifications|pulse_[a-z_]+|telescope_[a-z_]+)$'
COMPOSE=(docker compose --profile prod)

while [ $# -gt 0 ]; do
  case "$1" in
    --backup-dir) BACKUP_DIR="$2"; shift 2 ;;
    --dump) DUMP="$2"; shift 2 ;;
    --keep) KEEP=1; shift ;;
    --allow-newer-prod) ALLOW_NEWER_PROD=1; shift ;;
    -h|--help) sed -n '2,29p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 3 ;;
  esac
done

log() { printf '%s %s\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { local code=$1; shift; log "ERROR: $*"; exit "$code"; }

for tool in docker gzip tar awk sort df stat; do
  command -v "$tool" >/dev/null 2>&1 || die 3 "missing tool: $tool"
done
[ -f compose.yml ] || [ -f docker-compose.yml ] || die 3 "run from the compose project directory"
[ -d "$BACKUP_DIR" ] || die 3 "backup dir $BACKUP_DIR not found"

TMP="$(mktemp -d)"
chmod 700 "$TMP"
RC=0
FAILS=0
WARNS=0
declare -a STEP_NAMES=() STEP_SECS=()
T_START=$(date +%s)

cleanup() {
  local code=$?
  if [ "$KEEP" = 1 ]; then
    log "--keep: leaving scratch objects ($DB_CT, network $NET, volumes $PGVOL $STVOL, $TMP)"
    return
  fi
  docker rm -f "$DB_CT" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  docker volume rm -f "$PGVOL" "$STVOL" >/dev/null 2>&1 || true
  rm -rf "$TMP"
  return $code
}
trap cleanup EXIT

step_begin() { STEP_T0=$(date +%s); STEP_NAME="$1"; log "== $1"; }
step_end() { STEP_NAMES+=("$STEP_NAME"); STEP_SECS+=("$(( $(date +%s) - STEP_T0 ))"); }
fail() { FAILS=$((FAILS + 1)); log "FAIL: $*"; }
warn() { WARNS=$((WARNS + 1)); log "WARN: $*"; }
ok() { log "PASS: $*"; }

# Idempotent: drop leftovers of an earlier (aborted or --keep) run.
docker rm -f "$DB_CT" >/dev/null 2>&1 || true
docker network rm "$NET" >/dev/null 2>&1 || true
docker volume rm -f "$PGVOL" "$STVOL" >/dev/null 2>&1 || true

# ---------------------------------------------------------------- 1. pre-checks
step_begin "pre-checks"
if [ -z "$DUMP" ]; then
  DUMP="$(ls -1 "$BACKUP_DIR"/db-*.sql.gz 2>/dev/null | sort | tail -n 1 || true)"
fi
[ -n "$DUMP" ] && [ -f "$DUMP" ] || die 3 "no db-*.sql.gz found in $BACKUP_DIR"
STAMP="$(basename "$DUMP" .sql.gz)"; STAMP="${STAMP#db-}"
STORAGE_ARCHIVE="$(dirname "$DUMP")/storage-$STAMP.tgz"
log "dump set: $STAMP"

if [ -f "$BACKUP_DIR/.last-success" ]; then
  AGE_H=$(( ( $(date +%s) - $(cat "$BACKUP_DIR/.last-success") ) / 3600 ))
  if [ "$AGE_H" -le 26 ]; then ok "backup marker age ${AGE_H}h"; else warn "backup marker is ${AGE_H}h old (> 26h)"; fi
else
  warn "no .last-success marker"
fi
DUMP_BYTES=$(stat -c %s "$DUMP")
DUMP_MTIME=$(stat -c %Y "$DUMP")
gzip -t "$DUMP" 2>/dev/null || die 3 "dump is not a valid gzip archive"
ok "dump gzip -t ($DUMP_BYTES bytes)"
HAVE_STORAGE=0
if [ -f "$STORAGE_ARCHIVE" ]; then
  gzip -t "$STORAGE_ARCHIVE" 2>/dev/null || die 3 "storage archive is not a valid gzip archive"
  tar tzf "$STORAGE_ARCHIVE" >/dev/null 2>&1 || die 3 "storage archive is not a readable tar"
  HAVE_STORAGE=1
  ok "storage archive gzip -t + tar listing ($(stat -c %s "$STORAGE_ARCHIVE") bytes)"
else
  warn "no matching storage archive for $STAMP, storage step skipped"
fi
RAW_BYTES=$(gzip -l "$DUMP" | awk 'NR==2 {print $2}')
DOCKER_ROOT="$(docker info -f '{{.DockerRootDir}}' 2>/dev/null || echo /var/lib/docker)"
AVAIL=$(df -B1 --output=avail "$DOCKER_ROOT" | tail -n 1 | tr -d ' ')
if [ "$AVAIL" -gt $(( RAW_BYTES * 2 )) ]; then
  ok "free disk $((AVAIL / 1048576)) MiB > 2x dump ($((RAW_BYTES / 1048576)) MiB uncompressed)"
else
  die 3 "not enough free disk ($((AVAIL / 1048576)) MiB) for 2x $((RAW_BYTES / 1048576)) MiB"
fi

# Production connection facts (names only, never printed).
PG_USER="$("${COMPOSE[@]}" exec -T postgres sh -c 'printf %s "$POSTGRES_USER"')" || die 3 "cannot read production postgres"
PG_DB="$("${COMPOSE[@]}" exec -T postgres sh -c 'printf %s "$POSTGRES_DB"')"
PG_IMAGE="$(docker inspect --format '{{.Config.Image}}' "$("${COMPOSE[@]}" ps -q postgres)")"
APP_IMAGE="$(docker inspect --format '{{.Image}}' "$("${COMPOSE[@]}" ps -q app)")"
[ -n "$PG_USER" ] && [ -n "$PG_DB" ] && [ -n "$PG_IMAGE" ] && [ -n "$APP_IMAGE" ] || die 3 "cannot resolve production postgres/app facts"
log "postgres image: $PG_IMAGE"
step_end

# --------------------------------------------------------------- 2. restore
step_begin "scratch postgres + restore"
SCRATCH_PW="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
# --internal: no route to the outside, and the network is separate from prod.
docker network create --internal "$NET" >/dev/null || die 3 "cannot create network $NET"
docker volume create "$PGVOL" >/dev/null
docker run -d --name "$DB_CT" --network "$NET" -v "$PGVOL":/var/lib/postgresql/data \
  --cpus 1 --memory 1g --cpu-shares 128 \
  -e POSTGRES_USER="$PG_USER" -e POSTGRES_PASSWORD="$SCRATCH_PW" -e POSTGRES_DB="$PG_DB" \
  "$PG_IMAGE" >/dev/null || die 3 "cannot start scratch postgres"
# The image's init starts a temporary server, then restarts postgres: the real
# server is up once "ready to accept connections" has been logged twice.
for _ in $(seq 1 90); do
  [ "$(docker logs "$DB_CT" 2>&1 | grep -c 'database system is ready to accept connections')" -ge 2 ] && break
  sleep 1
done
docker exec "$DB_CT" pg_isready -q -U "$PG_USER" -d "$PG_DB" || die 3 "scratch postgres did not become ready"

R0=$(date +%s)
# The scratch role has the production role's name, so plain-dump OWNER TO
# statements work without --no-owner.
if nice -n 19 gunzip -c "$DUMP" \
    | docker exec -i "$DB_CT" psql -q -v ON_ERROR_STOP=1 -U "$PG_USER" -d "$PG_DB" \
      >/dev/null 2>"$TMP/restore.err"; then
  RESTORE_SECS=$(( $(date +%s) - R0 ))
  ok "restore finished in ${RESTORE_SECS}s"
else
  RESTORE_SECS=$(( $(date +%s) - R0 ))
  log "psql stderr line count: $(wc -l < "$TMP/restore.err")"
  grep -m1 '^psql:.*ERROR:' "$TMP/restore.err" | cut -c1-200 || true
  exit 1
fi
step_end

# ------------------------------------------------------------- 3. compare DBs
step_begin "compare production vs restored"
# $1 = prod|scratch, SQL on stdin. Production is forced read-only.
sql() {
  if [ "$1" = prod ]; then
    "${COMPOSE[@]}" exec -T -e PGOPTIONS='-c default_transaction_read_only=on' postgres \
      sh -c 'psql -X -At -F"|" -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f -'
  else
    docker exec -i "$DB_CT" psql -X -At -F'|' -v ON_ERROR_STOP=1 -U "$PG_USER" -d "$PG_DB" -f -
  fi
}

COUNTS_SQL="select table_schema||'.'||table_name,
  (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I.%I', table_schema, table_name), false, true, '')))[1]::text
  from information_schema.tables
  where table_type='BASE TABLE' and table_schema not in ('pg_catalog','information_schema')
  order by 1;"
SCHEMA_SQL="select 'constraints_'||contype::text||'|'||count(*) from pg_constraint c join pg_namespace n on n.oid=c.connamespace
  where n.nspname not in ('pg_catalog','information_schema') group by contype
  union all select 'indexes|'||count(*) from pg_indexes where schemaname not in ('pg_catalog','information_schema')
  union all select 'sequences|'||count(*) from pg_sequences where schemaname not in ('pg_catalog','information_schema')
  union all select 'migrations_rows|'||count(*) from migrations
  union all select 'migrations_last|'||(select migration from migrations order by id desc limit 1)
  order by 1;"
SEQ_SQL="select count(*) from (
  select (xpath('/row/m/text()', query_to_xml(format('select max(%I) as m from %I.%I', a.attname, n.nspname, t.relname), false, true, '')))[1]::text::bigint as maxid,
         s.last_value
  from pg_depend d
  join pg_class sc on sc.oid=d.objid and sc.relkind='S'
  join pg_class t on t.oid=d.refobjid
  join pg_namespace n on n.oid=t.relnamespace
  join pg_attribute a on a.attrelid=t.oid and a.attnum=d.refobjsubid
  join pg_sequences s on s.sequencename=sc.relname and s.schemaname=n.nspname
  where d.deptype in ('a','i') and n.nspname not in ('pg_catalog','information_schema')) x
  where coalesce(maxid,0) > coalesce(last_value,0);"

# explained <schema.table> <restored_count>: production has more rows than the
# restore. That is only acceptable when the surplus was written AFTER the dump:
# production rows with created_at <= dump time must equal the restored count.
explained() {
  local tbl=$1 restored=$2 has before
  has=$(sql prod <<<"select count(*) from information_schema.columns where table_schema||'.'||table_name='$tbl' and column_name='created_at';") || return 1
  [ "$has" = 1 ] || return 1
  before=$(sql prod <<<"select count(*) from $tbl where created_at <= (to_timestamp($DUMP_MTIME) at time zone 'UTC');") || return 1
  EXPLAIN_BEFORE=$before
  [ "$before" = "$restored" ]
}

# compare_counts <lenient>: lenient=1 (dump predates a deploy) reports
# production-newer differences as INFO instead of FAIL.
compare_counts() {
  local lenient=$1
  sql prod <<<"$COUNTS_SQL" >"$TMP/counts.prod" || die 3 "production count query failed"
  sql scratch <<<"$COUNTS_SQL" >"$TMP/counts.restored" || die 3 "restored count query failed"
  log "tables: production $(wc -l < "$TMP/counts.prod"), restored $(wc -l < "$TMP/counts.restored")"
  awk -F'|' -v drift_re="$DRIFT_RE" -v allow_newer="$ALLOW_NEWER_PROD" -v lenient="$lenient" '
    NR==FNR { p[$1]=$2; keys[$1]=1; next }
    { r[$1]=$2; keys[$1]=1 }
    END {
      for (k in keys) {
        if (!(k in p)) { printf "TABLEONLY|%s|restored\n", k; continue }
        if (!(k in r)) { printf "TABLEONLY|%s|production\n", k; continue }
        if (p[k]==r[k]) { eq++; continue }
        d = p[k]-r[k]
        if (k ~ drift_re) printf "DRIFT|%s|prod=%s restored=%s delta=%+d\n", k, p[k], r[k], d
        else if ((allow_newer==1 || lenient==1) && d>0) printf "NEWER|%s|prod=%s restored=%s delta=%+d\n", k, p[k], r[k], d
        else printf "MISMATCH|%s|prod=%s restored=%s delta=%+d\n", k, p[k], r[k], d
      }
      printf "EQUAL|%d|tables with identical counts\n", eq
    }' "$TMP/counts.prod" "$TMP/counts.restored" | sort > "$TMP/counts.diff"
  while IFS='|' read -r kind name rest; do
    case "$kind" in
      EQUAL) ok "$name tables have identical row counts" ;;
      DRIFT) log "INFO: expected drift $name: $rest" ;;
      NEWER|MISMATCH)
        restored_n="${rest#*restored=}"; restored_n="${restored_n%% *}"
        case "$rest" in *delta=+*) delta_pos=1 ;; *) delta_pos=0 ;; esac
        if [ "$delta_pos" = 1 ] && explained "$name" "$restored_n"; then
          log "INFO: explained by writes after the dump, $name: $rest (production rows created before the dump: $EXPLAIN_BEFORE)"
        elif [ "$kind" = NEWER ]; then
          log "INFO: production newer than dump in $name: $rest"
        else
          fail "row-count mismatch $name: $rest"
        fi ;;
      TABLEONLY)
        if [ "$lenient" = 1 ]; then log "INFO: table $name exists only in $rest (pending migration)"; else fail "table $name exists only in $rest"; fi ;;
    esac
  done < "$TMP/counts.diff"
}

# Dump older than the running release? (migration rows differ, production ahead)
MIG_PROD=$(sql prod <<<"select count(*) from migrations;")
MIG_RESTORED=$(sql scratch <<<"select count(*) from migrations;")
DUMP_PREDATES_DEPLOY=0
if [ "$MIG_PROD" -gt "$MIG_RESTORED" ]; then
  DUMP_PREDATES_DEPLOY=1
  warn "dump predates a deploy: production has $MIG_PROD migrations, dump has $MIG_RESTORED (restoring it needs 'php artisan migrate --force')"
elif [ "$MIG_PROD" -lt "$MIG_RESTORED" ]; then
  fail "dump has MORE migrations ($MIG_RESTORED) than production ($MIG_PROD)"
fi
NOW=$(date +%s)
log "dump age at comparison: $(( (NOW - DUMP_MTIME) / 60 )) min (writes since the dump explain growth)"
compare_counts "$DUMP_PREDATES_DEPLOY"
TABLES_RESTORED=$(wc -l < "$TMP/counts.restored")

sql prod <<<"$SCHEMA_SQL" >"$TMP/schema.prod" || die 3 "production schema query failed"
sql scratch <<<"$SCHEMA_SQL" >"$TMP/schema.restored" || die 3 "restored schema query failed"
[ -s "$TMP/schema.restored" ] || die 3 "schema query returned nothing"
if [ "$DUMP_PREDATES_DEPLOY" = 1 ]; then
  log "INFO: schema comparison deferred until after the forward migration"
elif diff -q "$TMP/schema.prod" "$TMP/schema.restored" >/dev/null; then
  ok "schema facts equal (constraints by type, indexes, sequences, migrations rows/last): $(tr '\n' ' ' < "$TMP/schema.restored" | sed 's/migrations_last|[^ ]*//')"
else
  fail "schema facts differ:"
  diff "$TMP/schema.prod" "$TMP/schema.restored" | grep '^[<>]' | sed 's/^</  prod:/; s/^>/  restored:/' || true
fi
log "last migration (dump): $(grep '^migrations_last|' "$TMP/schema.restored" | cut -d'|' -f2)"
BAD_SEQ=$(sql scratch <<<"$SEQ_SQL") || die 3 "sequence query failed"
if [ "$BAD_SEQ" = "0" ]; then ok "all sequences positioned at or beyond max(id)"; else fail "$BAD_SEQ sequences are behind max(id)"; fi
step_end

# ----------------------------------------------------------- 4. app on restore
step_begin "app against restored database"
# Sanitised env file: production .env with integrations blanked. Never printed,
# lives only in $TMP (removed on exit).
[ -r "$ENV_FILE" ] || die 3 "cannot read $ENV_FILE"
BLANK_RE='^(DISCORD_|STEAM_|TWITCH_|PELICAN_|MUMBLE_|TEAMSPEAK_|MUSIC_ASSISTANT_|REGISTRY_|MAIL_|AWS_|REDIS_|LANCACHE_|REVERB_APP_SECRET|SENTRY_|ACME_)'
awk -F= -v blank_re="$BLANK_RE" '
  /^[[:space:]]*#/ || !/=/ { next }
  { k=$1; sub(/^[[:space:]]+/, "", k); v=substr($0, index($0, "=")+1)
    gsub(/\r$/, "", v)
    if (v ~ /^".*"$/ || v ~ /^'\''.*'\''$/) v=substr(v, 2, length(v)-2)
    if (k ~ blank_re) v=""
    print k "=" v }' "$ENV_FILE" > "$TMP/app.env"
chmod 600 "$TMP/app.env"

APP_RUN=(docker run --rm --network "$NET" --env-file "$TMP/app.env"
  -e DB_CONNECTION=pgsql -e DB_HOST="$DB_CT" -e DB_PORT=5432 -e DB_DATABASE="$PG_DB"
  -e DB_USERNAME="$PG_USER" -e DB_PASSWORD="$SCRATCH_PW"
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array
  -e BROADCAST_CONNECTION=log -e MAIL_MAILER=log -e LOG_CHANNEL=stderr
  -e REDIS_HOST= -e DISCORD_BOT_TOKEN= -e DISCORD_CLIENT_ID= -e DISCORD_CLIENT_SECRET=
  -e DISCORD_GATEWAY_INGRESS_URL= -e PELICAN_PANEL_URL= -e PELICAN_APPLICATION_TOKEN=
  -e PELICAN_CLIENT_TOKEN= -e STEAM_API_KEY= -e TWITCH_CLIENT_ID= -e TWITCH_CLIENT_SECRET=
  --cpus 1 --memory 1g "$APP_IMAGE")

migrate_status() {
  MS_OUT="$("${APP_RUN[@]}" php artisan migrate:status --no-ansi 2>&1 || true)"
  MS_RAN=$(grep -c -E '\bRan\b' <<<"$MS_OUT" || true)
  MS_PENDING=$(grep -c -E '\bPending\b' <<<"$MS_OUT" || true)
}
migrate_status
if [ "$DUMP_PREDATES_DEPLOY" = 1 ]; then
  log "INFO: before forward migration: $MS_RAN ran, $MS_PENDING pending"
  M0=$(date +%s)
  if "${APP_RUN[@]}" php artisan migrate --force --no-ansi >/dev/null 2>"$TMP/migrate.err"; then
    ok "forward migration on the restored DB finished in $(( $(date +%s) - M0 ))s"
  else
    fail "forward migration failed ($(wc -l < "$TMP/migrate.err") stderr lines)"
  fi
  migrate_status
  log "re-comparing restored (migrated) DB with production"
  compare_counts 0
  sql scratch <<<"$SCHEMA_SQL" >"$TMP/schema.restored"
  if diff -q "$TMP/schema.prod" "$TMP/schema.restored" >/dev/null; then ok "schema facts equal after migration"; else fail "schema facts differ after migration"; fi
fi
if [ "$MS_RAN" -gt 0 ] && [ "$MS_PENDING" = 0 ]; then ok "migrate:status: $MS_RAN ran, 0 pending"; else fail "migrate:status: $MS_RAN ran, $MS_PENDING pending (or command failed)"; fi

PF_OUT="$("${APP_RUN[@]}" php artisan lanomat:preflight 2>&1 || true)"
PF_DB="$(grep -i -m1 -E '(database|datenbank)' <<<"$PF_OUT" | tr -s ' ' | cut -c1-80 || true)"
if grep -qi -E '(database|datenbank).*(ok|pass|✓)' <<<"$PF_DB"; then ok "preflight DB row: $PF_DB"; else fail "preflight DB row not OK: ${PF_DB:-<none>}"; fi

# Usability + APP_KEY dependence. Prints counts and ok/failed only.
read -r -d '' TINKER <<'PHP' || true
$t = fn ($n) => \Illuminate\Support\Facades\DB::table($n)->count();
foreach (['users', 'events', 'event_registrations', 'games'] as $n) {
    echo $n.'='.(\Illuminate\Support\Facades\Schema::hasTable($n) ? $t($n) : 'n/a').PHP_EOL;
}
foreach ([['linked_accounts', 'access_token'], ['remote_hosts', 'ssh_private_key']] as [$tbl, $col]) {
    if (! \Illuminate\Support\Facades\Schema::hasTable($tbl)) { echo "decrypt $tbl.$col=n/a".PHP_EOL; continue; }
    $v = \Illuminate\Support\Facades\DB::table($tbl)->whereNotNull($col)->value($col);
    if ($v === null) { echo "decrypt $tbl.$col=skipped(no rows)".PHP_EOL; continue; }
    try { \Illuminate\Support\Facades\Crypt::decryptString($v); echo "decrypt $tbl.$col=ok".PHP_EOL; }
    catch (\Throwable $e) { echo "decrypt $tbl.$col=failed".PHP_EOL; }
}
PHP
USE_OUT="$("${APP_RUN[@]}" php artisan tinker --execute="$TINKER" 2>&1 || true)"
USE_LINES="$(grep -E '^(users|events|event_registrations|games)=|^decrypt ' <<<"$USE_OUT" || true)"
if [ -z "$USE_LINES" ]; then
  fail "usability check produced no result"
else
  while IFS= read -r l; do log "  $l"; done <<<"$USE_LINES"
  if grep -q '=failed' <<<"$USE_LINES"; then fail "an encrypted column could not be decrypted with the production APP_KEY"; else ok "usability/decryption check ran without failures"; fi
fi
step_end

# ------------------------------------------------------------ 5. storage
step_begin "storage archive"
if [ "$HAVE_STORAGE" = 1 ]; then
  docker volume create "$STVOL" >/dev/null
  docker run --rm --network none -v "$STVOL":/data -v "$(dirname "$STORAGE_ARCHIVE")":/backup:ro alpine \
    sh -c "tar xzf /backup/$(basename "$STORAGE_ARCHIVE") -C /data" || die 1 "storage extract failed"
  stats='find . -type f | wc -l; find . -type f -exec stat -c %s {} + 2>/dev/null | awk "{s+=\$1} END {print s+0}"'
  RESTORED_STATS="$(docker run --rm --network none -v "$STVOL":/data:ro -w /data alpine sh -c "$stats" | tr '\n' ' ')"
  LIVE_STATS="$(docker run --rm --network none -v "$STORAGE_VOLUME":/data:ro -w /data alpine sh -c "$stats" | tr '\n' ' ')"
  log "files/bytes restored: $RESTORED_STATS| live: $LIVE_STATS"
  if [ "$RESTORED_STATS" = "$LIVE_STATS" ]; then ok "storage file count and size equal"; else warn "storage differs from live (uploads since the backup are expected)"; fi
  SAMPLE="$(docker run --rm --network none -v "$STVOL":/data:ro -w /data alpine sh -c 'find . -type f | sort | head -n 5')"
  SAMPLE_OK=0; SAMPLE_N=0
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    SAMPLE_N=$((SAMPLE_N + 1))
    A="$(docker run --rm --network none -v "$STVOL":/data:ro -w /data alpine sha256sum "$f" | cut -d' ' -f1)"
    B="$(docker run --rm --network none -v "$STORAGE_VOLUME":/data:ro -w /data alpine sh -c "sha256sum '$f' 2>/dev/null" | cut -d' ' -f1 || true)"
    [ "$A" = "$B" ] && SAMPLE_OK=$((SAMPLE_OK + 1))
  done <<<"$SAMPLE"
  if [ "$SAMPLE_N" -eq 0 ]; then log "INFO: archive contains no files to checksum"
  elif [ "$SAMPLE_OK" = "$SAMPLE_N" ]; then ok "sample checksums equal ($SAMPLE_OK/$SAMPLE_N)"
  else warn "sample checksums equal for $SAMPLE_OK/$SAMPLE_N files (changed or removed since the backup)"; fi
else
  log "skipped (no storage archive)"
fi
step_end

# ------------------------------------------------------------- summary
TOTAL=$(( $(date +%s) - T_START ))
log "== summary"
log "dump: $DUMP_BYTES bytes ($((RAW_BYTES / 1024)) KiB uncompressed), restore ${RESTORE_SECS}s, tables $TABLES_RESTORED"
for i in "${!STEP_NAMES[@]}"; do log "  step ${STEP_NAMES[$i]}: ${STEP_SECS[$i]}s"; done
log "total drill time: ${TOTAL}s, failures: $FAILS, warnings: $WARNS"
if [ "$FAILS" -gt 0 ]; then
  log "RESULT: VERIFICATION MISMATCH"
  RC=2
else
  log "RESULT: PASS"
fi
exit "$RC"
