# syntax=docker/dockerfile:1
#
# NFS Online Server (Underground 2 / Most Wanted / Carbon), AGPL-3.0-or-later.
# Upstream: https://github.com/moio9/NFS-Online-Server (see docs/ops/nfsu2-server.md)
#
# The server sources are supplied as the named build context `nfs`, so the
# checkout can live anywhere on the operator's machine:
#
#   docker build \
#     --build-context nfs=/opt/NFS-Online-Server \
#     -f docker/nfs-online/Dockerfile \
#     -t lanomat/nfs-online:local docker/nfs-online
#
# docker/nfs-online/compose.yml does this automatically.
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1

WORKDIR /app

# Server package (Python 3.12, sqlite3 comes from the standard library).
COPY --from=nfs . /app

# First-run seed for the named config/data volumes. Docker copies /app/config
# and /app/data into an empty volume; the entrypoint restores this seed when a
# volume is completely empty (e.g. a bind mount). Runtime state is stripped so
# no test account from the build machine can leak into a fresh deployment.
RUN mkdir -p /opt/nfs-online-seed \
    && cp -a /app/config /opt/nfs-online-seed/config \
    && cp -a /app/data /opt/nfs-online-seed/data \
    && for dir in /app /opt/nfs-online-seed; do \
         rm -f "$dir/data/accounts.sqlite3" \
               "$dir/data/server-state.json" \
               "$dir/data/carbon/dlc_assignments.json" \
               "$dir/data/carbon/mad_impressions.jsonl"; \
         find "$dir/data/users" \
              "$dir/data/classic" \
              "$dir/data/backups" \
              -mindepth 1 -delete; \
       done \
    && rm -rf /app/runtime /app/logs/* \
    && mkdir -p /app/logs

COPY docker-entrypoint.sh /usr/local/bin/nfs-online-entrypoint
RUN chmod 0755 /usr/local/bin/nfs-online-entrypoint

# Shared EA Messenger, U2 bootstrap, U2 lobby, U2/MW control+PREL,
# and the six channelized race-relay UDP ports (20000..20005).
EXPOSE 13505/tcp 20921/tcp 20922/tcp 20923/tcp
EXPOSE 20000/udp 20001/udp 20002/udp 20003/udp 20004/udp 20005/udp

# The launcher runs in the foreground (no --daemon): its console keeps the
# process alive and its child logs are tee'd to stdout.
ENTRYPOINT ["/usr/local/bin/nfs-online-entrypoint"]
