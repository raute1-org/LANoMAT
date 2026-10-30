#!/usr/bin/env bash
# Installs /etc/cron.d/lanomat-backup, running scripts/ops/backup.sh daily at 03:00.
# Usage (as root): scripts/ops/install-backup-cron.sh [project-dir]   (default /opt/lanomat)
set -euo pipefail

DIR="${1:-/opt/lanomat}"
[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 3; }
[ -x "$DIR/scripts/ops/backup.sh" ] || { echo "$DIR/scripts/ops/backup.sh not found/executable" >&2; exit 3; }

install -d -m 0750 /var/backups/lanomat
cat > /etc/cron.d/lanomat-backup <<CRON
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
0 3 * * * root cd $DIR && ./scripts/ops/backup.sh >> /var/log/lanomat-backup.log 2>&1
CRON
chmod 0644 /etc/cron.d/lanomat-backup
echo "Installed /etc/cron.d/lanomat-backup (project dir: $DIR)"
