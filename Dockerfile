# syntax=docker/dockerfile:1

# ---- frontend assets (Vite) ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---- php runtime (php-fpm + nginx, non-root) ----
FROM serversideup/php:8.4-fpm-nginx AS app
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
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && php artisan filament:assets --ansi \
 && mkdir -p /data storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data /data storage bootstrap/cache
USER www-data
