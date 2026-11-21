#!/bin/sh
# First-run setup and foreground launch for the NFS Online Server container.
# See docs/ops/nfsu2-server.md.
set -eu

cd /app

PUBLIC_HOST="${PUBLIC_HOST:-127.0.0.1}"
NFS_GAMES="${NFS_GAMES:-u2}"

# The config/ and data/ volumes start empty. Restore the image seed once so a
# fresh volume is usable without a manual copy step. Runtime state written
# later (accounts.sqlite3, users/, server-state.json) stays in the volume.
if [ ! -f /app/config/server.toml ]; then
    echo "nfs-online: seeding /app/config from image defaults"
    cp -a /opt/nfs-online-seed/config/. /app/config/
fi
if [ -z "$(ls -A /app/data 2>/dev/null)" ]; then
    echo "nfs-online: seeding /app/data from image defaults"
    cp -a /opt/nfs-online-seed/data/. /app/data/
fi

mkdir -p /app/data/users /app/logs

# PUBLIC_HOST is what the server advertises to game clients: an IP or DNS name,
# never host:port. Change it only through the environment of the container.
python3 nfs_online.py configure --public-host "$PUBLIC_HOST"

# Admin passthrough, e.g.
#   docker compose run --rm nfs-online account list
#   docker compose run --rm nfs-online create-account
#   docker compose run --rm nfs-online check
if [ "$#" -gt 0 ]; then
    exec python3 nfs_online.py "$@"
fi

# Foreground mode: no --daemon, logs go to stdout, the interactive console
# keeps PID 1 alive (compose sets stdin_open: true).
# shellcheck disable=SC2086
exec python3 nfs_online.py start --games "$NFS_GAMES" ${NFS_ONLINE_EXTRA_ARGS:-}
