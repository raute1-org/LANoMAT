# Build the LANoMAT docs site (Zudoku) and serve it as static files under /docs.
# Build context: docs-site/  —  docker build -t lanomat-docs docs-site

FROM node:22-alpine AS build

WORKDIR /app

# Dependencies first for a cacheable layer. package-lock.json is committed.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

# basePath is /docs, so the output lands in dist/docs/.
RUN npm run build

FROM nginx:1.27-alpine AS runtime

# Replace the default site; keep nginx's own mime/types and gzip defaults.
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Static output: dist/ contains docs/ (the site) and the status pages.
COPY --from=build /app/dist /usr/share/nginx/html

# nginx's master runs as root to bind :80; workers run as the unprivileged
# nginx user. The document root is read-only at runtime.
RUN chown -R root:root /usr/share/nginx/html && chmod -R a+rX /usr/share/nginx/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1/docs/ || exit 1

STOPSIGNAL SIGQUIT

CMD ["nginx", "-g", "daemon off;"]
