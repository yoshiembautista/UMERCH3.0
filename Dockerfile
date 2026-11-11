# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# 1. Frontend assets (Vite + React + Tailwind)
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

# Public key only. Never pass secrets as Docker build arguments.
ARG VITE_RECAPTCHA_SITE_KEY=""
ENV VITE_RECAPTCHA_SITE_KEY=$VITE_RECAPTCHA_SITE_KEY

RUN npm run build

# ---------------------------------------------------------------------------
# 2. PHP dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# ---------------------------------------------------------------------------
# 3. Runtime (FrankenPHP + Laravel)
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    opcache \
    gd \
    bcmath \
    exif \
    curl

# FrankenPHP ships with cap_net_bind_service set on its binary. Restricted
# container runtimes (Render, and anything using no-new-privileges/cap-drop)
# refuse to exec a file that carries capabilities, which surfaces as
# "exec: frankenphp: Operation not permitted" and exit code 126. We listen on
# an unprivileged port, so the capability is unnecessary.
RUN setcap -r /usr/local/bin/frankenphp

WORKDIR /app

# Application code first, then the build artifacts from the earlier stages.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/99-umerch.ini
COPY docker/entrypoint.sh /usr/local/bin/umerch-entrypoint

RUN chmod +x /usr/local/bin/umerch-entrypoint \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

# Render injects PORT and forwards traffic there; the Caddyfile reads it.
# Cloudflare Containers leaves PORT unset and uses 8080.
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/umerch-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
