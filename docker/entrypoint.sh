#!/bin/sh
set -eu

cd /app

export LOG_CHANNEL="${LOG_CHANNEL:-stderr}"

# Neon can be asleep when the container cold-starts, so retry migrations.
attempt=0
until php artisan migrate --force --no-interaction; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 20 ]; then
        echo "Database migrations failed after ${attempt} attempts." >&2
        exit 1
    fi
    echo "Database not ready (attempt ${attempt}/20), retrying in 3s..." >&2
    sleep 3
done

# Do not run `php artisan optimize` here: this app has closure routes, which
# makes `route:cache` fail. Config and views are safe to cache.
php artisan config:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
