#!/bin/sh
set -eu

cd /var/www/html

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

if [ -d resources/views ]; then
    php artisan view:cache --no-interaction
fi

exec docker-php-entrypoint "$@"
