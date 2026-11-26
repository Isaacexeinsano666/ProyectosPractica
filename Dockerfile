# --- Etapa 1: compilar CSS/JS con Vite (Tailwind 4) ---
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci
COPY resources ./resources
COPY public ./public
RUN npm run build

# --- Etapa 2: PHP-FPM con la aplicación ---
FROM php:8.3-fpm

RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && docker-php-ext-install pdo_mysql bcmath opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencias PHP (se cachean si composer.lock no cambia)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Código de la aplicación y recursos compilados
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-scripts \
 && php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
