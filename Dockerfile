FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    nginx supervisor curl zip unzip python3 ffmpeg \
    libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev \
    libxml2-dev

RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp && \
    docker-php-ext-install pdo pdo_mysql bcmath gd

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .

RUN composer install --no-dev --optimize-autoloader

EXPOSE 80

CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=80"]
