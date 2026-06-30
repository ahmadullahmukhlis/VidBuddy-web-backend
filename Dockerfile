FROM php:8.4-fpm-alpine

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

# Copy your custom Nginx configuration
COPY nginx.conf /etc/nginx/http.d/default.conf

# Setup proper directory permissions for Laravel storage
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# Install dependencies and optimize production configurations
RUN composer install --no-dev --optimize-autoloader
RUN php artisan config:cache && php artisan route:cache && php artisan view:cache

EXPOSE 80

# Run migrations at startup, then run Nginx and PHP-FPM in the foreground together
CMD ["sh", "-c", "php artisan migrate --force && php-fpm -D && nginx -g 'daemon off;'"]
