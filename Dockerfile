# syntax=docker/dockerfile:1

# ---- PHP dependencies shared by the theme build and runtime ----
FROM serversideup/php:8.4-fpm-nginx AS php-dependencies
ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_MIGRATION_ISOLATION=false \
    SSL_MODE=off

USER root
RUN install-php-extensions intl
WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# ---- frontend assets (Vite + Filament theme) ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=php-dependencies /var/www/html/vendor ./vendor
COPY . .
RUN npm run build

# ---- php runtime (php-fpm + nginx, non-root) ----
FROM php-dependencies AS app
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && php artisan filament:assets --ansi \
 && mkdir -p /data storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data /data storage bootstrap/cache
USER www-data
