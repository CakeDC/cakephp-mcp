# php-fpm with intl, which CakePHP needs.
FROM mirror.gcr.io/library/php:8.4-fpm-alpine
RUN apk add --no-cache icu-libs \
    && apk add --no-cache --virtual .build-deps icu-dev $PHPIZE_DEPS \
    && docker-php-ext-install intl \
    && apk del .build-deps
