# syntax=docker/dockerfile:1

###############################################################################
# Dependencies -- resolved in their own stage so application changes do not
# invalidate the Composer cache.
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

###############################################################################
# Runtime
###############################################################################
FROM dunglas/frankenphp:1-php8.4 AS runtime

# gd is built with WebP and AVIF so the optimizer has every target format;
# pcntl lets queue workers handle SIGTERM instead of being killed mid-job.
RUN install-php-extensions \
        gd \
        exif \
        pcntl \
        pdo_pgsql \
        pdo_mysql \
        redis \
        intl \
        zip \
        opcache

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV SERVER_NAME=:8000

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
