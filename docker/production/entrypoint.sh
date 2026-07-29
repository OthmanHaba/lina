#!/usr/bin/env sh
set -eu

cd /var/www/html

mkdir -p \
    database \
    storage/app/loki \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

touch database/database.sqlite

if [ "${LINA_SKIP_OPTIMIZE:-0}" != "1" ]; then
    php artisan optimize
fi

exec docker-php-entrypoint "$@"
