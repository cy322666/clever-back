FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-req=ext-gd --ignore-platform-req=ext-intl

FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
RUN npm ci
RUN npm run build

FROM php:8.4-cli-bookworm AS app
WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libxml2-dev \
    libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql intl zip bcmath pcntl opcache gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY --from=vendor /app/vendor /opt/vendor
COPY --from=assets /app/public/build /var/www/html/public/build
COPY --from=assets /app/public/build /opt/build
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN php -r "require 'vendor/autoload.php';"
RUN rm -f bootstrap/cache/*.php
RUN chmod +x artisan
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["sh", "-lc", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000"]
