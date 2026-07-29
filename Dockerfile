# syntax=docker/dockerfile:1.7

FROM php:8.4-apache-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libsqlite3-dev \
    && docker-php-ext-install opcache pcntl pdo_sqlite \
    && a2enmod expires headers rewrite \
    && rm -rf /var/lib/apt/lists/*

FROM php-base AS php-builder

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY . .

RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan wayfinder:generate --with-form --no-interaction

FROM node:22-bookworm-slim AS frontend-builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY --from=php-builder /app /app
RUN npm run build

FROM php-base AS runtime

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY --from=php-builder --chown=www-data:www-data /app /var/www/html
COPY --from=frontend-builder --chown=www-data:www-data /app/public/build /var/www/html/public/build
COPY docker/production/apache.conf /etc/apache2/conf-available/lina.conf
COPY docker/production/entrypoint.sh /usr/local/bin/lina-entrypoint

RUN a2enconf lina \
    && chmod 0755 /usr/local/bin/lina-entrypoint \
    && mkdir -p \
        database \
        storage/app/loki \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && touch database/database.sqlite \
    && chown -R www-data:www-data bootstrap/cache database storage

ENTRYPOINT ["lina-entrypoint"]
CMD ["apache2-foreground"]
