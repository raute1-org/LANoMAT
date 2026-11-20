#!/bin/sh
# Docker HEALTHCHECK for the docs container.
#
# No curl: the nginx Alpine runtime only ships BusyBox, and BusyBox wget is
# enough for a single 200 + non-empty-body probe. Runs as the unprivileged
# nginx user (uid 101), so it must not need any privileges.
#
# Exit 0 = healthy, 1 = unhealthy. Docker wraps the output into
# `docker inspect --format '{{.State.Health.Status}}'`.
set -eu

PORT="${DOCS_HEALTHCHECK_PORT:-8080}"
URL="http://127.0.0.1:${PORT}/docs/"

# -q quiet, -T 4 seconds timeout, -O - write the body to stdout.
if ! body="$(wget -q -T 4 -O - "$URL")"; then
    echo "docs healthcheck: ${URL} did not return 200" >&2
    exit 1
fi

# The entry page is small but non-empty (it is the client-side redirect to
# /docs/start/introduction). An empty body means a broken static root.
if [ -z "$body" ]; then
    echo "docs healthcheck: ${URL} returned an empty body" >&2
    exit 1
fi

exit 0
