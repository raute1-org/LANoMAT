# syntax=docker/dockerfile:1
#
# Container image for the LANoMAT agent CLI (`lanomat-agent`).
#
#   docker build --build-arg VERSION=0.1.0 -t lanomat-agent clients/agent
#   docker run --rm -it -v "$HOME/.config/lanomat:/home/node/.config/lanomat" lanomat-agent --version
#
# The image is a convenience/runtime path (LAN servers, CI smoke tests); the primary
# distribution is the npm tarball (`npm pack`) because the agent talks to ~/.pi, the
# user's game installs and their desktop. It therefore runs as the unprivileged `node`
# user (uid 1000) and keeps no writable state outside $HOME.
#
# Build args:
#   NODE_IMAGE  base image for both stages, default node:24-alpine (LTS; CI also tests 22)
#   VERSION     required, must equal the package.json version; the build fails without it
#   REVISION    source commit, default "unknown"
#   SOURCE      repository URL

ARG NODE_IMAGE=node:24-alpine

# ---- build: full dev dependencies, TypeScript compile ----
FROM ${NODE_IMAGE} AS build
WORKDIR /app

# Only manifests first so the dependency layer survives source changes.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY tsconfig.json ./
COPY src ./src
RUN npm run build \
 && npm prune --omit=dev --no-audit --no-fund \
 && find node_modules -mindepth 1 -maxdepth 2 -type d -empty -delete \
 && rm -rf node_modules/.package-lock.json

# ---- runtime: production dependencies + compiled dist, unprivileged user ----
FROM ${NODE_IMAGE} AS runtime

# Re-declared so the label below names the base image this layer was really built on.
ARG NODE_IMAGE
# No default: an image labelled with the wrong version is how a release drifts from
# the tarball. The validation below also refuses a VERSION that is not package.json's.
ARG VERSION
ARG REVISION=unknown
ARG SOURCE=https://github.com/raute1-org/LANoMAT

LABEL org.opencontainers.image.title="lanomat-agent" \
      org.opencontainers.image.description="LANoMAT desktop installer agent CLI" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.revision="${REVISION}" \
      org.opencontainers.image.source="${SOURCE}" \
      org.opencontainers.image.url="${SOURCE}/tree/main/clients/agent" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.base.name="docker.io/library/${NODE_IMAGE}"

ENV NODE_ENV=production \
    NPM_CONFIG_UPDATE_NOTIFIER=false \
    HOME=/home/node

# The base image ships npm, corepack/yarn and the Node headers for building; this
# image only runs a compiled CLI, so they go (~30 MB of the running filesystem). No
# npm means no accidental in-container installs either.
RUN rm -rf /usr/local/lib/node_modules/npm \
           /usr/local/lib/node_modules/corepack \
           /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack \
           /usr/local/bin/yarn /usr/local/bin/yarnpkg /opt/yarn-v1.22.22 \
           /usr/local/include/node

WORKDIR /app

# Production dependency tree comes from the pruned build stage: same lockfile, no
# second install, no dev-only packages in the runtime layer.
COPY --from=build --chown=node:node /app/package.json /app/package-lock.json ./

# The version label must be the packaged version; empty means the caller forgot the
# build arg, any other value means the image would lie about what it contains.
RUN set -eu; \
    if [ -z "${VERSION:-}" ]; then \
      echo "ERROR: pass --build-arg VERSION=<package.json version> (e.g. 0.1.0)"; \
      exit 1; \
    fi; \
    packaged=$(node -p "require('/app/package.json').version"); \
    if [ "$VERSION" != "$packaged" ]; then \
      echo "ERROR: VERSION=${VERSION} but package.json is ${packaged}"; \
      exit 1; \
    fi

COPY --from=build --chown=node:node /app/node_modules ./node_modules
COPY --from=build --chown=node:node /app/dist ./dist
COPY --chown=node:node README.md LICENSE ./

USER node

ENTRYPOINT ["node", "dist/cli.js"]
CMD ["--help"]
