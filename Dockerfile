# Install PHP dependencies in a throwaway stage so Composer isn't in the final image
FROM composer:2 AS vendor
WORKDIR /app
# composer.lock is optional: without one, Composer resolves fresh versions
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

FROM php:8.4-apache
# GD with JPEG/WebP support for image_compressed.php
RUN apt-get update \
 && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install gd \
 && rm -rf /var/lib/apt/lists/*
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY *.php /var/www/html/
