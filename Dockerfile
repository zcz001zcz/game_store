FROM composer:2.8 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --ignore-platform-req=ext-curl \
    --ignore-platform-req=ext-dom \
    --ignore-platform-req=ext-mbstring \
    --ignore-platform-req=ext-pdo_pgsql \
    --ignore-platform-req=ext-xml \
    --ignore-platform-req=ext-xmlwriter

FROM php:8.3-cli-alpine

RUN apk add --no-cache curl libpq libxml2 oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS curl-dev libpq-dev libxml2-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" curl dom mbstring pcntl pdo_pgsql xml xmlwriter \
    && apk del .build-deps \
    && addgroup -S app \
    && adduser -S -G app app

WORKDIR /app

COPY --from=vendor /app/vendor /app/vendor
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
COPY . /app

RUN chown -R app:app /app
USER app

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]
