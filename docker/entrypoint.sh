#!/bin/sh
# Container start: build Laravel's caches now that the runtime env vars are available, then serve.
set -e
cd /app

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec frankenphp run --config /etc/frankenphp/Caddyfile
