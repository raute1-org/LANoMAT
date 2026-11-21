#!/usr/bin/env bash
# Put the teamspeak-admin sidecar's current container IP on the TeamSpeak ServerQuery allowlist.
#
# Why: the sidecar opens a fresh ServerQuery session (connect, login, use) for every REST call. From an
# IP that is not on query_ip_allowlist.txt TeamSpeak counts that as flooding and drops the connection
# for a few minutes ("connect ok, banner empty"). Every call then runs into the sidecar's 30 s socket
# timeout, the presence refresh job blocks the queue worker, and Preflight shows "Queue-Worker DOWN".
#
# Idempotent and never restarts anything. TeamSpeak reads the file only at start, so when it had to add
# a line the script says that TeamSpeak must be restarted once (it briefly disconnects clients):
#   docker compose --profile prod restart teamspeak
# The compose network hands out a new IP when the sidecar container is recreated: run this again then
# (deploy.sh calls it at the end).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

TS=$(docker compose --profile prod ps -q teamspeak 2>/dev/null || true)
SIDECAR=$(docker compose --profile prod ps -q teamspeak-admin 2>/dev/null || true)
if [ -z "$TS" ] || [ -z "$SIDECAR" ]; then
    echo "teamspeak or teamspeak-admin is not running: nothing to do."
    exit 0
fi

IP=$(docker inspect "$SIDECAR" -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' | awk '{print $1}')
if ! [[ "$IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "could not determine the sidecar IP (got '$IP')" >&2
    exit 1
fi

FILE=/var/ts3server/query_ip_allowlist.txt
if docker exec "$TS" grep -qxF "$IP" "$FILE" 2>/dev/null; then
    echo "TeamSpeak ServerQuery allowlist already has $IP."
    exit 0
fi

docker exec "$TS" sh -c "printf '%s\n' '$IP' >> $FILE"
echo "Added $IP to the TeamSpeak ServerQuery allowlist."
echo "NOTE: TeamSpeak reads it at start. Restart it once (clients reconnect within seconds):"
echo "      docker compose --profile prod restart teamspeak"
