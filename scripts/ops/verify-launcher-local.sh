#!/usr/bin/env bash
# Local acceptance: the launcher core installs UT2004 from a real LANoMAT server.
#
# Not a wiremock test. It brings up the real Laravel app from the lanomat-dev
# image against a throwaway lanomat_demo database, seeds the real
# ScreenshotSeeder (recipe + pinned LAN files + demo MCP token), serves it over
# real HTTP on 127.0.0.1:8000 and runs the real installer example inside the
# launcher toolchain image:
#
#   cargo run --example install_local   (launcher-core, --network host)
#
# The "DVD" and the OldUnreal patch are tiny fixtures from scripts/ops/fixtures/:
# a real ISO 9660/Joliet image and a real tar.bz2 whose first entry is "./" (the
# root entry that used to break extraction). shared_files.size_bytes is updated
# to the fixture sizes because the installer treats the announced size as
# authoritative.
#
# Usage:
#   scripts/ops/verify-launcher-local.sh [--keep] [--port N]
#
#   --keep     keep the lanomat-demo container, the lanomat_demo database and the
#              temp dir (default: everything is cleaned up; on failure the temp
#              dir with all logs stays)
#   --port N   host port for the demo server (default 8000)
#
# Requirements: Docker (and permission to use it), the images lanomat-dev (the
# app image; rebuild with `docker build -t lanomat-dev -f docker/Dockerfile .`
# after server code changes) and lanomat-launcher-dev (scripts/ops/build-launcher.sh),
# plus the compose postgres container lanomat-postgres-1 (started automatically
# with `docker compose up -d postgres` when missing). The host has no Rust
# toolchain; the installer runs in Docker. Port 8000 must be free; an existing
# container named lanomat-demo is replaced. The first run compiles
# launcher-core into the lanomat-launcher-verify-target volume (reused later);
# the cargo registry is the lanomat-cargo-registry volume.
#
# The token is ScreenshotSeeder::MCP_AGENT_TOKEN, the documented demo token. The
# APP_KEY below is a throwaway demo key, not a secret.
#
# Exit codes: 0 install verified | 1 failure (logs kept) | 2 usage/precondition.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURES="$ROOT/scripts/ops/fixtures"
ISO_FIXTURE="$FIXTURES/ut2004-fixture.iso"
PATCH_FIXTURE="$FIXTURES/ut2004-patch-fixture.tar.bz2"

APP_IMAGE="lanomat-dev:latest"
LAUNCHER_IMAGE="lanomat-launcher-dev:latest"
PG="lanomat-postgres-1"
NETWORK="lanomat_default"
DEMO="lanomat-demo"
DB="lanomat_demo"
PG_USER=lanomat
PG_PASSWORD=lanomat
TOKEN="lmcp_screenshotAgentToken000000000000000000000000000"
PORT=8000
KEEP=0
TOOLS_PREFIX="lanomat-verify-$$"
RUN_ID=0

die() { printf 'verify-launcher-local: %s\n' "$*" >&2; exit 1; }
die2() { printf 'usage error: %s\n' "$*" >&2; exit 2; }
step() { printf '\n==> %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
log() { printf '==> %s\n' "$*"; }

next_name() { RUN_ID=$((RUN_ID + 1)); printf '%s-%s' "$TOOLS_PREFIX" "$RUN_ID"; }

# The Docker client can block on daemon hiccups (seen under very heavy parallel
# load: `docker run --rm` after the container exited, `docker rm` stuck in
# "removing"). Nothing here is allowed to hang the acceptance run forever.
docker_rm() { timeout 30 docker rm -f "$@" >/dev/null 2>&1 || true; }
docker_logs() { timeout 30 docker logs "$@" 2>&1 || true; }

# `docker run --rm` can hang after the container already exited, so one-shot
# helpers run detached, are polled and removed here. Echoes the container's
# exit code (124 on timeout).
wait_container() {
    local name=$1 limit=$2 waited=0 state rc
    while [ "$waited" -le "$limit" ]; do
        state="$(timeout 10 docker inspect -f '{{.State.Status}}' "$name" 2>/dev/null)" || { printf '125'; return 0; }
        case "$state" in
            exited|dead|removing)
                rc="$(timeout 10 docker inspect -f '{{.State.ExitCode}}' "$name" 2>/dev/null)"
                printf '%s' "${rc:-1}"
                return 0
                ;;
        esac
        sleep 1
        waited=$((waited + 1))
    done
    printf '124'
}

# run_oneshot <name> <timeout-seconds> <docker run args...>
run_oneshot() {
    local name=$1 limit=$2 rc
    shift 2
    docker_rm "$name"
    timeout 120 docker run -d --name "$name" "$@" >/dev/null || true
    rc="$(wait_container "$name" "$limit")"
    docker_logs "$name"
    docker_rm "$name"
    return "$rc"
}

usage() {
    sed -n '2,/^set -euo pipefail$/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
    case "$1" in
        --keep) KEEP=1 ;;
        --port) [ $# -ge 2 ] || die2 "--port needs a value"; PORT="$2"; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die2 "unknown argument: $1 (see --help)" ;;
    esac
    shift
done

case "$PORT" in
    ''|*[!0-9]*) die2 "--port must be a number (got '$PORT')" ;;
esac

# ---- preconditions -------------------------------------------------------------------------
step "preconditions"
command -v docker >/dev/null 2>&1 || die2 "docker is not installed"
command -v curl >/dev/null 2>&1 || die2 "curl is not installed"
command -v tar >/dev/null 2>&1 || die2 "tar is not installed"
docker info >/dev/null 2>&1 || die2 "docker is not reachable"
docker image inspect "$APP_IMAGE" >/dev/null 2>&1 \
    || die2 "image $APP_IMAGE is missing (build it with: docker build -t lanomat-dev -f docker/Dockerfile .)"
docker image inspect "$LAUNCHER_IMAGE" >/dev/null 2>&1 \
    || die2 "image $LAUNCHER_IMAGE is missing (build it with: scripts/ops/build-launcher.sh)"
[ -f "$ISO_FIXTURE" ] || die2 "fixture missing: ${ISO_FIXTURE#"$ROOT"/} (see scripts/ops/fixtures/README.md)"
[ -f "$PATCH_FIXTURE" ] || die2 "fixture missing: ${PATCH_FIXTURE#"$ROOT"/} (see scripts/ops/fixtures/README.md)"
patch_entries="$(tar -tjf "$PATCH_FIXTURE")"
grep -qx './' <<<"$patch_entries" \
    || die2 "the patch fixture has no './' root entry, so it does not exercise the case it exists for"

TMP_BASE="${TMPDIR:-/tmp/opencode}"
mkdir -p "$TMP_BASE" || die2 "cannot create $TMP_BASE"
WORK="$(mktemp -d "$TMP_BASE/lanomat-launcher-verify.XXXXXX")" || die2 "cannot create a temp dir"
STORAGE="$WORK/storage"
TARGET="$WORK/target/ut2004"
mkdir -p "$STORAGE/app/private" \
    "$STORAGE/framework/cache/data" \
    "$STORAGE/framework/sessions" \
    "$STORAGE/framework/views" \
    "$STORAGE/logs" \
    "$WORK/target"

ISO_BYTES="$(stat -c %s "$ISO_FIXTURE")"
PATCH_BYTES="$(stat -c %s "$PATCH_FIXTURE")"
note "temp dir: $WORK"
note "fixtures:  $(basename "$ISO_FIXTURE") $ISO_BYTES B, $(basename "$PATCH_FIXTURE") $PATCH_BYTES B"

# ---- cleanup ---------------------------------------------------------------------------------
drop_db() {
    timeout 10 docker container inspect "$PG" >/dev/null 2>&1 || return 0
    timeout 30 docker exec "$PG" psql -U "$PG_USER" -d postgres -v ON_ERROR_STOP=1 \
        -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB' AND pid <> pg_backend_pid()" \
        >/dev/null 2>&1 || true
    timeout 30 docker exec "$PG" psql -U "$PG_USER" -d postgres -v ON_ERROR_STOP=1 \
        -c "DROP DATABASE IF EXISTS $DB" >/dev/null 2>&1 || true
}

remove_work() {
    rm -rf "$WORK" 2>/dev/null && return 0
    # Containers ran as root and may have written into the bind-mounted dirs.
    run_oneshot "$(next_name)-cleanup" 120 -v "$WORK:/work" --entrypoint sh "$APP_IMAGE" \
        -c 'rm -rf /work/* /work/.[!.]*' >/dev/null 2>&1 || true
    rm -rf "$WORK" 2>/dev/null || true
}

cleanup() {
    local status=$?
    trap - EXIT
    set +e

    # Helper containers get a name of their own so a killed run never leaves
    # them behind.
    timeout 30 docker ps -aq --filter "name=$TOOLS_PREFIX" | xargs -r -n1 timeout 20 docker rm -f >/dev/null 2>&1

    if timeout 10 docker container inspect "$DEMO" >/dev/null 2>&1; then
        docker_logs "$DEMO" >"$WORK/demo-container.log"
        if [ "$KEEP" -eq 1 ]; then
            log "keeping container $DEMO and database $DB (--keep; remove with: docker rm -f $DEMO)"
        else
            docker_rm "$DEMO"
        fi
    fi

    [ "$KEEP" -eq 1 ] || drop_db

    if [ "$status" -ne 0 ] || [ "$KEEP" -eq 1 ]; then
        log "kept work dir: $WORK"
        [ -s "$WORK/launcher-out/stdout.log" ] && log "installer phase log: $WORK/launcher-out/stdout.log"
        [ -s "$WORK/launcher-out/stderr.log" ] && log "installer build log: $WORK/launcher-out/stderr.log"
        [ -s "$WORK/demo-container.log" ] && log "server log:         $WORK/demo-container.log"
        [ -s "$WORK/seed.log" ] && log "seed log:           $WORK/seed.log"
    else
        remove_work
        log "cleaned up container, database $DB and temp dir"
    fi

    exit "$status"
}
trap cleanup EXIT

# ---- throwaway database and postgres ---------------------------------------------------------
step "postgres"
if ! docker container inspect "$PG" >/dev/null 2>&1; then
    command -v docker >/dev/null 2>&1
    docker compose version >/dev/null 2>&1 \
        || die "container $PG is missing and 'docker compose' is not available"
    log "starting $PG via docker compose"
    ( cd "$ROOT" && docker compose up -d postgres )
fi
docker start "$PG" >/dev/null 2>&1 || true

docker network inspect "$NETWORK" >/dev/null 2>&1 \
    || die "docker network $NETWORK is missing (start the stack once: docker compose up -d postgres)"
pg_networks="$(docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}} {{end}}' "$PG")"
if ! grep -qw "$NETWORK" <<<"$pg_networks"; then
    log "attaching $PG to $NETWORK"
    docker network connect --alias postgres "$NETWORK" "$PG"
fi

ready=0
for _ in $(seq 1 60); do
    if docker exec "$PG" pg_isready -U lanomat -d postgres >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[ "$ready" -eq 1 ] || die "$PG did not become ready within 60s"

# Same credentials the app must use. The compose defaults are lanomat/lanomat;
# a venue .env may have changed the password, so read it from the container.
PG_USER="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$PG" | sed -n 's/^POSTGRES_USER=//p')"
PG_PASSWORD="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$PG" | sed -n 's/^POSTGRES_PASSWORD=//p')"
PG_USER="${PG_USER:-lanomat}"
PG_PASSWORD="${PG_PASSWORD:-lanomat}"

if [ "$(docker exec "$PG" psql -U "$PG_USER" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$DB'")" != "1" ]; then
    log "creating database $DB"
    docker exec "$PG" psql -U "$PG_USER" -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE $DB" >/dev/null
else
    log "reusing database $DB (migrate:fresh wipes it)"
fi

# A stale demo container holds connections and port 8000; replace it.
docker_rm "$DEMO"
# Also drop connections a previous run (or a --keep container) may still hold.
timeout 30 docker exec "$PG" psql -U "$PG_USER" -d postgres -v ON_ERROR_STOP=1 \
    -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB' AND pid <> pg_backend_pid()" \
    >/dev/null 2>&1 || true

# ---- seed the app ----------------------------------------------------------------------------
# Docker passes these as real environment variables; /app/.env is removed below
# so nothing can shadow them. Value-free of secrets except the throwaway APP_KEY.
app_env=(
    -e APP_NAME=LANoMAT
    -e APP_ENV=local
    -e APP_DEBUG=false
    -e APP_KEY=base64:HVnwNS4D1iAEVkMPDAWe1XfGR56GUndEdovDax0Qwd8=
    -e "APP_URL=http://127.0.0.1:$PORT"
    -e APP_LOCALE=de
    -e APP_FALLBACK_LOCALE=en
    -e APP_DISPLAY_TIMEZONE=Europe/Berlin
    -e DB_CONNECTION=pgsql
    -e DB_HOST=postgres
    -e DB_PORT=5432
    -e "DB_DATABASE=$DB"
    -e "DB_USERNAME=$PG_USER"
    -e "DB_PASSWORD=$PG_PASSWORD"
    -e SESSION_DRIVER=file
    -e SESSION_ENCRYPT=false
    -e CACHE_STORE=array
    -e CACHE_LIMITER_STORE=database
    -e QUEUE_CONNECTION=sync
    -e BROADCAST_CONNECTION=null
    -e FILESYSTEM_DISK=local
    -e MODULES_PROFILE=full
    -e MAIL_MAILER=log
    -e LOG_CHANNEL=stack
    -e LOG_STACK=single
    -e LOG_LEVEL=debug
)

# The app container is started first and then used for the artisan steps: a
# long-lived container is far more reliable than one `docker run` per command
# (a short-lived container can die spuriously under Docker load), and it carries
# the same environment the HTTP server has. The server only has to answer /up
# after the seed, which is asserted below.
step "starting the demo app container"
timeout 120 docker run -d --name "$DEMO" --network "$NETWORK" \
    -p "127.0.0.1:$PORT:8000" \
    "${app_env[@]}" -v "$STORAGE:/app/storage" \
    --entrypoint sh "$APP_IMAGE" \
    -c 'rm -f /app/.env && exec php artisan serve --host=0.0.0.0 --port=8000' >/dev/null

run_artisan() {
    timeout 600 docker exec "$DEMO" sh -c "php artisan $1"
}

step "migrating and seeding $DB"
run_artisan 'migrate:fresh --force' 2>&1 | tee "$WORK/migrate.log"
run_artisan 'db:seed --class=ScreenshotSeeder --force' 2>&1 | tee "$WORK/seed.log"

# Laravel's local disk writes 0700 dirs (private visibility). The seeder ran as
# root, so hand the tree back to the invoking user before the host-side copy;
# the app container (root) can still write regardless of the owner.
timeout 60 docker exec "$DEMO" chown -R "$(id -u):$(id -g)" /app/storage \
    || die "could not hand the seeded storage tree back to uid $(id -u)"

# ---- fixture payload in the shared-file storage ----------------------------------------------
step "copying the fixture DVD/patch into storage"
names="'UT2004-DVD1.iso', 'OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2'"
rows="$(docker exec "$PG" psql -U "$PG_USER" -d "$DB" -At -F "$(printf '\t')" \
    -c "SELECT original_name, path FROM shared_files WHERE original_name IN ($names) ORDER BY original_name")"
row_count="$(printf '%s\n' "$rows" | sed '/^$/d' | wc -l)"
[ "$row_count" -eq 2 ] \
    || die "expected the seeder's pinned ISO and patch rows in shared_files, found $row_count (is $APP_IMAGE current?)"

while IFS=$'\t' read -r name path; do
    [ -n "$name" ] || continue
    case "$name" in
        *.iso) source_file="$ISO_FIXTURE" ;;
        *.tar.bz2) source_file="$PATCH_FIXTURE" ;;
        *) die "unexpected shared file '$name'" ;;
    esac
    [ -n "$path" ] || die "shared file '$name' has no storage path"
    dest="$STORAGE/app/private/$path"
    mkdir -p "$(dirname "$dest")"
    cp "$source_file" "$dest"
    chmod 0644 "$dest"
    note "$name -> $path ($(stat -c %s "$dest") bytes)"
done <<<"$rows"

step "aligning shared_files.size_bytes with the fixtures"
docker exec -i "$PG" psql -U "$PG_USER" -d "$DB" -v ON_ERROR_STOP=1 \
    -v iso="$ISO_BYTES" -v patch="$PATCH_BYTES" >/dev/null <<'SQL'
UPDATE shared_files SET size_bytes = :iso   WHERE original_name = 'UT2004-DVD1.iso';
UPDATE shared_files SET size_bytes = :patch WHERE original_name = 'OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2';
SQL
iso_row="$(docker exec "$PG" psql -U "$PG_USER" -d "$DB" -tAc "SELECT size_bytes FROM shared_files WHERE original_name = 'UT2004-DVD1.iso'")"
patch_row="$(docker exec "$PG" psql -U "$PG_USER" -d "$DB" -tAc "SELECT size_bytes FROM shared_files WHERE original_name = 'OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2'")"
[ "$iso_row" = "$ISO_BYTES" ] || die "size_bytes of the ISO row is $iso_row, expected $ISO_BYTES"
[ "$patch_row" = "$PATCH_BYTES" ] || die "size_bytes of the patch row is $patch_row, expected $PATCH_BYTES"
note "ISO $iso_row B, patch $patch_row B"

# ---- wait for the seeded app ----------------------------------------------------------------
step "waiting for the demo server on 127.0.0.1:$PORT"
ready=0
for _ in $(seq 1 60); do
    if curl -fsS --max-time 2 -o /dev/null "http://127.0.0.1:$PORT/up"; then ready=1; break; fi
    if [ "$(docker inspect -f '{{.State.Running}}' "$DEMO" 2>/dev/null || true)" != "true" ]; then break; fi
    sleep 1
done
[ "$ready" -eq 1 ] || {
    timeout 30 docker logs "$DEMO" >&2 || true
    die "the demo server did not answer /up within 60s"
}
note "/up is up"

step "smoke-checking the recipe plan over HTTP"
plan="$(curl -fsS --max-time 20 \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    --data '{"os":"linux","dry_run":true}' \
    "http://127.0.0.1:$PORT/api/v1/recipes/unreal-tournament-2004/plan")" \
    || die "the recipe plan request failed (is the MCP token seeded?)"
grep -q '"missing":\[\]' <<<"$plan" \
    || die "the plan still reports missing required files: $(grep -o '"missing":\[[^]]*\]' <<<"$plan" || true)"
grep -q 'UT2004-DVD1.iso' <<<"$plan" || die "the plan does not match the fixture ISO"
grep -q 'OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2' <<<"$plan" || die "the plan does not match the fixture patch"
note "plan: no missing files, ISO and patch matched"

# ---- run the real installer ------------------------------------------------------------------
step "running the launcher core installer (cargo run --example install_local)"
cargo_name="$(next_name)-cargo"
docker_rm "$cargo_name"
timeout 120 docker run -d --name "$cargo_name" --network host \
    -v "$ROOT/clients/launcher:/work" \
    -v lanomat-launcher-verify-target:/work/target \
    -v "$WORK/target:/target" \
    -v "$WORK/launcher-out:/out" \
    -v lanomat-cargo-registry:/usr/local/cargo/registry \
    -w /work/launcher-core \
    -e "LANOMAT_URL=http://127.0.0.1:$PORT" \
    -e "LANOMAT_TOKEN=$TOKEN" \
    -e "LANOMAT_TARGET=/target/ut2004" \
    -e "HOME=/target" \
    "$LAUNCHER_IMAGE" \
    sh -c 'cargo run --locked --example install_local > /out/stdout.log 2> /out/stderr.log' >/dev/null
note "compiling launcher-core (first run takes a few minutes) and installing"
INSTALL_RC="$(wait_container "$cargo_name" 3600)"
docker_logs "$cargo_name" >"$WORK/cargo-container.log"
docker_rm "$cargo_name"

if [ "$INSTALL_RC" -eq 124 ]; then
    die "the installer did not finish within 60 minutes"
fi

if [ "$INSTALL_RC" -ne 0 ]; then
    printf '\n--- installer stdout ---\n' >&2
    cat "$WORK/launcher-out/stdout.log" >&2 || true
    printf '\n--- installer stderr (last 60 lines) ---\n' >&2
    tail -n 60 "$WORK/launcher-out/stderr.log" >&2 || true
    die "install_local exited $INSTALL_RC"
fi

# ---- assertions ------------------------------------------------------------------------------
step "asserting the installed tree"
failed=0
require_file() {
    if [ -f "$TARGET/$1" ]; then
        printf '    ok    %s\n' "$1"
    else
        printf '    FAIL  %s is missing\n' "$1"
        failed=1
    fi
}
require_file 'System/Build.ini'
require_file 'System/ut2004-bin-amd64'
require_file 'Maps/DM-Rankin.ut2'

printf '\nInstalled tree (%s):\n' "$TARGET"
( cd "$TARGET" && find . -type f -printf '    %-56P %8s bytes\n' | sort )

if [ "$failed" -ne 0 ]; then
    printf '\n--- installer phase log ---\n' >&2
    cat "$WORK/launcher-out/stdout.log" >&2 || true
    die "the install is incomplete (see the tree above)"
fi

[ -x "$TARGET/System/ut2004-bin-amd64" ] || { printf '    FAIL  System/ut2004-bin-amd64 is not executable\n'; failed=1; }
grep -q 'patched 64-bit' "$TARGET/System/UT2004.exe" 2>/dev/null || {
    printf '    FAIL  System/UT2004.exe does not come from the patch (disc System/ leaked?)\n'
    failed=1
}
cmp -s "$FIXTURES/iso/Maps/DM-Rankin.ut2" "$TARGET/Maps/DM-Rankin.ut2" || {
    printf '    FAIL  Maps/DM-Rankin.ut2 differs from the disc fixture\n'
    failed=1
}
grep -q 'texture replaced by the patch' "$TARGET/Textures/Test.utx" 2>/dev/null || {
    printf '    FAIL  Textures/Test.utx was not overwritten by the patch\n'
    failed=1
}

if [ "$failed" -ne 0 ]; then
    die "the install does not match the fixture expectations"
fi

step "installer phase log"
sed 's/^/    /' "$WORK/launcher-out/stdout.log"

log "PASS: the launcher core installed UT2004 from the seeded LANoMAT server"
