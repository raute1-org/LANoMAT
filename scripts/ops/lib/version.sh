#!/usr/bin/env bash
# Sourced, not executed. The ONE place that decides what "the LANoMAT version" is, so the server
# image (scripts/deploy/deploy.sh -> compose build args -> config('app.version')) and the native
# voice client (scripts/ops/build-client.sh -> LANOMAT_BUILD_VERSION) report byte-identical strings.
#
#   lanomat_version <repo>   `git describe --tags --always --dirty`, e.g. m12-463-g0a3b6ff
#                            (`-dirty` when tracked files have uncommitted changes), `dev` outside a repo
#   lanomat_commit <repo>    the 12-character commit id, `unknown` outside a repo
#   lanomat_version_ok <v>   succeeds when <v> is a safe version string (also a safe file name part)

lanomat_version() {
    git -C "${1:?repository path}" describe --tags --always --dirty 2>/dev/null || echo dev
}

lanomat_commit() {
    git -C "${1:?repository path}" rev-parse --short=12 HEAD 2>/dev/null || echo unknown
}

lanomat_version_ok() {
    [[ "${1:-}" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]]
}
