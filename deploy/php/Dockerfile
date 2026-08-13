FROM composer:2.10.2 AS vendor

WORKDIR /app
COPY SkinCare/composer.json SkinCare/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --no-scripts \
    --classmap-authoritative

FROM php:8.5.8-fpm-trixie AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN set -eux; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY SkinCare/ ./
COPY --from=vendor /app/vendor ./vendor
COPY frontend/ ./public/
COPY deploy/php/php.ini /usr/local/etc/php/conf.d/99-sharafi.ini
COPY deploy/php/entrypoint.sh /usr/local/bin/sharafi-entrypoint

RUN set -eux; \
    chmod 0755 /usr/local/bin/sharafi-entrypoint; \
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    APP_ENV=production APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=' php artisan package:discover --ansi

USER www-data

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/sharafi-entrypoint"]
CMD ["php-fpm", "-F"]
