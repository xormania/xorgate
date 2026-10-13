# syntax=docker/dockerfile:1
# Adapted from dunglas/symfony-docker; upstream reference in NOTICE.
FROM dunglas/frankenphp:1-php8.5@sha256:a052ae7cccd4a025eca17767e7acac57a4fc660a4f3dfc790be9b9cd5182d6e6 AS frankenphp_base

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1 PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && install-php-extensions @composer intl zip pdo_pgsql pcntl opcache mbstring dom xml xmlreader xmlwriter simplexml curl sodium \
    && rm -rf /var/lib/apt/lists/*

COPY frankenphp/conf.d/10-app.ini /usr/local/etc/php/app.conf.d/10-app.ini
COPY frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/xorgate-entrypoint
ENTRYPOINT ["xorgate-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

FROM frankenphp_base AS frankenphp_dev
ENV APP_ENV=dev XDEBUG_MODE=off FRANKENPHP_WORKER_CONFIG=watch
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY frankenphp/conf.d/20-app.dev.ini /usr/local/etc/php/app.conf.d/20-app.dev.ini
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch"]

FROM frankenphp_base AS frankenphp_prod
ENV APP_ENV=prod APP_DEBUG=0
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY . /app
RUN test -f composer.lock \
    && composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-progress \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console tailwind:build --minify \
    && php bin/console asset-map:compile \
    && mkdir -p var/cache var/log /var/lib/xorgate/artifacts \
    && chown -R www-data:www-data var /var/lib/xorgate/artifacts
USER www-data
