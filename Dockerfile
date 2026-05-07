FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx supervisor curl \
    && apk add --no-cache --virtual .build-deps autoconf g++ make \
    && docker-php-ext-install pdo pdo_mysql \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apk del .build-deps \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini \
    && echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/zz-memory.ini \
    && echo "display_errors=Off" >> /usr/local/etc/php/conf.d/zz-memory.ini \
    && echo "log_errors=On" >> /usr/local/etc/php/conf.d/zz-memory.ini \
    && echo "variables_order=EGPCS" >> /usr/local/etc/php/conf.d/zz-memory.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .

RUN mkdir -p /run/nginx /run/php

COPY nginx.conf /etc/nginx/http.d/default.conf
COPY supervisord.conf /etc/supervisord.conf

EXPOSE 8787

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
