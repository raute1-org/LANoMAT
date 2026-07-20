# Production image for LANoMAT v2, served by FrankenPHP (https://frankenphp.dev).
#
# FrankenPHP has native Laravel support (mounting a Laravel project "just
# works" via its bundled Caddy server / `php_server` directive) — no Octane
# runtime is required for correct serving. This image deliberately does NOT
# add `laravel/octane`: Octane's worker-mode persistence (booting the app
# once and re-using it across requests) is an opt-in performance upgrade
# with its own caveats (static state leaking between requests, "memory
# leak"-shaped bugs in code that isn't request-scoped-clean). LANoMAT v2 has
# no first-party benchmarked need for that trade yet, so this ships the
# straightforward "one process, classic per-request boot" FrankenPHP mode —
# already known-fast — and Octane can be added later as a pure opt-in
# (`php artisan octane:install --server=frankenphp`) if a real bottleneck
# shows up. See the M5 task-14 report for the docs consulted.
#
# Base tag: dunglas/frankenphp pins a PHP-8.4 build off Debian bookworm/
# trixie ("php8.4", not "-alpine" — the Alpine variant is musl-libc, which
# would work too, but the `*-php8.4` default variant is what FrankenPHP's
# own docs use for the Laravel examples, so this follows that path for the
# least surprise). 1.12.4 is the latest stable release as of 2026-07
# (verified against Docker Hub's tag list).
#
# Single "builder" stage: the frontend build (`npm run build`) shells out to
# `php artisan wayfinder:generate` (see vite.config.ts's `wayfinder()`
# plugin) to generate typed route helpers, so it needs the full Composer
# vendor/autoload and a bootable app — it cannot run in an isolated Node-only
# stage. Building both PHP deps and frontend assets here, then copying only
# the runtime-relevant output into the final stage, keeps the shipped image
# free of Node/npm/dev-composer-packages.
ARG FRANKENPHP_VERSION=1.12.4-php8.4

FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS builder

# Extensions the app actually uses at runtime (see README's "Requirements"
# and .github/workflows/ci.yml): pdo_pgsql (Postgres), redis (cache/queue/
# broadcasting driver), sodium (Discord Ed25519 interaction verification —
# already compiled into the base image, listed here for clarity/idempotency
# of the RUN), intl/zip (Laravel-ecosystem optional-but-recommended, per
# composer.lock suggests). `gd`/`imagick` are deliberately NOT installed:
# the QR-code path (app/Modules/Registration/Support/QrCode.php) renders
# via BaconQrCode's SvgImageBackEnd, which needs no image extension at all
# — a verified deviation from the task brief's "gd if the QR/image path
# needs it", not an oversight.
RUN install-php-extensions \
    pdo_pgsql \
    redis \
    sodium \
    intl \
    zip \
    opcache \
    pcntl

# Node 22, matching package.json's engines / README requirement, for the
# frontend asset build below (removed from the final image — see the last
# stage, which copies only `public/build` out of here).
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .

# --no-dev here would strip laravel/pail, mockery, larastan, etc, but also
# anything `wayfinder:generate` might transitively need at runtime; the
# actual `--no-dev` production install happens in the final stage's own
# `composer install` after this build output is copied in, so both the
# frontend build and the shipped vendor/ tree get a full, correct resolve.
RUN composer install --no-interaction --no-progress

# Wayfinder's route-type generation needs an application key to boot the
# app; a throwaway one is fine here since this is build-time codegen only,
# never shipped or persisted into the final image.
RUN cp .env.example .env && php artisan key:generate --ansi

RUN npm ci && npm run build

FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS app

RUN install-php-extensions \
    pdo_pgsql \
    redis \
    sodium \
    intl \
    zip \
    opcache \
    pcntl

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=builder /app/public/build ./public/build

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && rm /usr/bin/composer \
    && php artisan config:clear \
    && php artisan event:clear \
    && php artisan route:clear \
    && php artisan view:clear

# The official dunglas/frankenphp image runs as root by default (verified:
# `docker run --rm dunglas/frankenphp:1.12.4-php8.4 whoami` -> root; it does
# not drop privileges itself, unlike some of FrankenPHP's own advanced
# examples that add a dedicated user + `setcap` to bind :80/:443 without
# root). Running as root works out of the box and keeps this Dockerfile
# simple; www-data ownership is still set here so storage/bootstrap-cache
# stay writable if this is ever switched to a non-root `USER` later (a
# straightforward, low-risk hardening step, not done in this task to keep
# scope to what M5 Task 14 asks for).
RUN chown -R www-data:www-data storage bootstrap/cache

# The base image's own /etc/frankenphp/Caddyfile already does the right
# thing for a Laravel app via its SERVER_ROOT/SERVER_NAME env-var defaults
# (root public/, php_server catch-all) — verified by booting this exact
# image and confirming `GET /up` returns 200. This Dockerfile still ships
# its own minimal Caddyfile (docker/frankenphp/Caddyfile) for explicitness/
# auditability (no commented-out Mercure/Vulcain scaffolding, worker mode
# documented as off), loaded via an explicit `--config` rather than trying
# to `import` a fragment into the base image's own Caddyfile (that file
# expects an *unwrapped* fragment via `{$FRANKENPHP_CONFIG}` inside its
# `frankenphp {}` block, not a full Caddyfile with its own top-level `{}`
# global block — verified by a failed container start with "Unexpected '{'
# on a new line" before this was corrected), and rather than relying on
# frankenphp's cwd-Caddyfile auto-detection (verified NOT to trigger here:
# the base entrypoint's default `frankenphp run` args don't pick up
# /app/Caddyfile even though WORKDIR is /app).
ENV SERVER_NAME=":80"
COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile

# Raise PHP upload limits (see the file's header): large voice-client installer
# and M7 file-sharing uploads exceed PHP's stock 2 MB/8 MB defaults. Dropped
# into conf.d so it layers over the base image's php.ini without replacing it.
COPY docker/frankenphp/php.ini /usr/local/etc/php/conf.d/lanomat.ini
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -f http://localhost/up || exit 1
