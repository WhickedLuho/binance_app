FROM php:8.2-fpm-alpine

RUN apk add --no-cache curl curl-dev git unzip \
    && docker-php-ext-install curl opcache \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www/html