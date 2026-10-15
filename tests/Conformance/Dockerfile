# php-fpm with intl, which CakePHP needs. PHP_VERSION follows the host that
# installed vendor/ (see run.sh), so Composer's platform check passes.
ARG PHP_VERSION=8.4
FROM mirror.gcr.io/library/php:${PHP_VERSION}-fpm-alpine
RUN apk add --no-cache icu-libs \
    && apk add --no-cache --virtual .build-deps icu-dev $PHPIZE_DEPS \
    && docker-php-ext-install intl \
    && apk del .build-deps
