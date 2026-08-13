#!/bin/sh
set -eu

cd /var/www/html

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec docker-php-entrypoint "$@"
