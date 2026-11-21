# syntax=docker/dockerfile:1.7
#
# LANoMAT docs site (Zudoku) — static container served under /docs.
#
#   docker build -t lanomat-docs docs-site
#   docker run --rm -p 4015:8080 lanomat-docs
#   curl -I http://127.0.0.1:4015/docs/
#
# Build context: docs-site/ (not the repo root). The build runs
# `npm ci && npm run build` — the same contract as CI — and copies only the
# static output into nginx. No Node, npm or build source ships in the image.
#
# Runtime is `nginxinc/nginx-unprivileged`, the official non-root variant of
# the nginx image: it runs as uid 101 (`nginx`) with no root master, listens
# on :8080 (unprivileged port) and keeps its pid/temp files under /tmp.
# That is why the Traefik service label points at 8080, not 80.
#
# Tags are moving MINOR pins, like the app image (docker/Dockerfile): every
# rebuild picks up the newest patch of that line (security fixes). Override
# with --build-arg for a full patch pin, e.g.
#   docker build --build-arg NGINX_VERSION=1.29.8-alpine -t lanomat-docs .
# Deploy scripts should build with `--pull` so an old cached base is not
# reused indefinitely.

ARG NODE_VERSION=22-alpine
# `-alpine-slim` drops the bundled dynamic modules (geoip, image-filter,
# njs, ...) none of which a static site needs; the Alpine busybox tooling
# (including the wget used by the healthcheck) stays.
ARG NGINX_VERSION=1.29-alpine-slim

# --- build stage ----------------------------------------------------------

FROM node:${NODE_VERSION} AS build

WORKDIR /app

# The lockfile is committed; `npm ci` (never `install`) is the contract.
# The cache mount keeps repeated local builds fast without bloating layers.
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund

COPY . .

# Writes dist/docs/ (basePath is /docs) plus the prerendered status pages at
# dist/400.html, dist/404.html, dist/500.html. Then drop what only the build
# server needed: source maps (14 MB), the Nitro output and the Vite manifest.
# Serving the site needs none of them and they would double the image size.
RUN npm run build \
 && if grep -R -q '/docs/docs/' dist/docs; then \
        echo "ERROR: raw anchors double the basePath (/docs/docs/...):"; \
        grep -R -l '/docs/docs/' dist/docs; \
        exit 1; \
    fi \
 && find dist -name '*.map' -delete \
 && rm -rf dist/.output dist/docs/.vite

# --- runtime stage --------------------------------------------------------

FROM nginxinc/nginx-unprivileged:${NGINX_VERSION} AS runtime

# The base image already runs as uid 101. Build-time setup below edits files
# owned by root (mime.types, the stock document root), so it runs as root and
# switches back before the image is committed.
USER root

ARG APP_VERSION=dev
LABEL org.opencontainers.image.title="LANoMAT Docs" \
      org.opencontainers.image.description="Static Zudoku documentation for LANoMAT, served under /docs." \
      org.opencontainers.image.source="https://github.com/raute1-org/LANoMAT" \
      org.opencontainers.image.version="${APP_VERSION}"

# Replace the stock site; keep nginx's own mime.types and http-level defaults.
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY deploy/nginx-security-headers.conf /etc/nginx/snippets/docs-security-headers.conf

# Teach nginx the two formats the docs build emits that its stock mime.types
# does not know: application/yaml (the OpenAPI document) and text/markdown
# (the per-page .md sources linked from llms.txt). Inserted before the
# closing brace of the mapping. The two greps are the actual guard: if a
# future base image changes the file shape, the sed silently does nothing and
# nginx would serve YAML/Markdown as octet-stream — fail the build instead.
RUN sed -i 's|^}|    application/yaml yaml yml;\n    text/markdown md;\n}|' /etc/nginx/mime.types \
 && grep -q 'application/yaml yaml yml;' /etc/nginx/mime.types \
 && grep -q 'text/markdown md;' /etc/nginx/mime.types

# The stock image ships its own index.html and 50x.html. Drop them: this
# server owns only the docs (and the prerendered 404 page), so /index.html
# outside /docs must be a real 404 rather than nginx's welcome page.
RUN rm -f /usr/share/nginx/html/index.html /usr/share/nginx/html/50x.html

# Health probe uses BusyBox wget (no curl in the image).
COPY --chmod=0755 deploy/healthcheck.sh /usr/local/bin/docs-healthcheck.sh

# The site root plus the prerendered 404 page (its asset URLs point at
# /docs/assets, so it renders inside the normal shell with status 404).
COPY --from=build /app/dist/404.html /usr/share/nginx/html/404.html
COPY --from=build /app/dist/docs /usr/share/nginx/html/docs

# Fail the build, not the deploy, on a broken config.
RUN nginx -t

# uid 101 — non-root at runtime (the base image sets it; explicit for readers
# and so the HEALTHCHECK/CMD below run unprivileged).
USER nginx

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD ["/usr/local/bin/docs-healthcheck.sh"]

STOPSIGNAL SIGQUIT

CMD ["nginx", "-g", "daemon off;"]
