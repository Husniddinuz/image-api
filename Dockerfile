# syntax=docker/dockerfile:1

###############################################################################
# Dependencies -- resolved in their own stage so that editing application code
# does not invalidate the Composer cache.
###############################################################################
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-interaction

###############################################################################
# Runtime
###############################################################################
FROM dunglas/frankenphp:1-php8.4 AS runtime

# gd is built with WebP and AVIF so the optimizer has every target format;
# pcntl lets queue workers handle SIGTERM instead of dying mid-job.
RUN install-php-extensions \
        gd \
        exif \
        pcntl \
        pdo_pgsql \
        pdo_mysql \
        redis \
        zip \
        opcache

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app

COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/app/private \
             storage/logs \
             bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV SERVER_NAME=:8000

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
