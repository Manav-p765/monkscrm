# syntax=docker/dockerfile:1
#
# Production image for Render (see CLAUDE.md "Hosting").
# Server: FrankenPHP, i.e. Caddy + PHP in one process. Simpler than nginx + php-fpm + a
# process supervisor for a single-container web service.
#
# Build:  docker build -t monks-crm .
# Run:    docker run -p 8080:8080 -e APP_KEY=... -e DB_URL=... monks-crm
# No .env is baked in: all configuration comes from environment variables at runtime.

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------------------
# base: PHP runtime + extensions + Composer. Also used to run the test suite
# against PostgreSQL locally (docker build --target base ...).
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm AS base

RUN install-php-extensions pdo_pgsql intl zip bcmath opcache pcntl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

# ---------------------------------------------------------------------------
# vendor: production PHP dependencies with an optimized, authoritative classmap.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
    && APP_ENV=build php artisan package:discover --ansi
# (APP_ENV=build: the production boot guard needs runtime secrets that a build never has.)

# ---------------------------------------------------------------------------
# assets: Vite build in a separate Node stage (Node is not in the final image).
# ---------------------------------------------------------------------------
FROM node:20-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
# Tailwind scans these for class names: PHP components (e.g. badge tones) and pagination views.
COPY app ./app
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# ---------------------------------------------------------------------------
# runtime
# ---------------------------------------------------------------------------
FROM base AS runtime

# Safe, non-secret defaults. Secrets (APP_KEY, DB_URL/DB_PASSWORD, MAIL_*) come from Render env vars.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    DB_CONNECTION=pgsql \
    DB_SSLMODE=require \
    SESSION_DRIVER=database \
    BCRYPT_ROUNDS=10 \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    PORT=8080 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

# The base image gives frankenphp the file capability cap_net_bind_service (to bind ports < 1024).
# Render runs containers with no-new-privileges and without that capability, so the kernel refuses to
# exec a binary that demands it ("Operation not permitted", exit 126). We only bind $PORT (> 1024),
# so remove it. Never add capabilities back.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcap2-bin \
    && setcap -r /usr/local/bin/frankenphp \
    && apt-get purge -y --auto-remove libcap2-bin \
    && rm -rf /var/lib/apt/lists/*

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

# Non-root user. Only storage/ and bootstrap/cache/ (framework caches) and Caddy's dirs are writable.
RUN useradd --uid 10001 --no-create-home --shell /usr/sbin/nologin app \
    && chown -R app:app /app/storage /app/bootstrap/cache /config/caddy /data/caddy

USER app
EXPOSE 8080

ENTRYPOINT ["entrypoint"]
