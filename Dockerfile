FROM php:8.4-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    nginx supervisor curl zip unzip git bash \
    ffmpeg \
    libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev \
    libxml2-dev oniguruma-dev

# PHP extensions (IMPORTANT FOR LARAVEL)
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp && \
    docker-php-ext-install \
    pdo pdo_mysql bcmath gd \
    mbstring xml fileinfo tokenizer

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

# Nginx config
COPY nginx.conf /etc/nginx/http.d/default.conf

# Laravel required folders
RUN mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Permissions (CRITICAL FIX)
RUN addgroup -g 1000 www && adduser -G www -u 1000 -D www && \
    chown -R www:www /var/www && \
    chmod -R 775 storage bootstrap/cache

# Install dependencies (NO cache here!)
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 80

# IMPORTANT: do NOT cache config at build time on Render
CMD sh -c "php artisan config:clear && \
           php artisan route:clear && \
           php artisan view:clear && \
           php-fpm -D && \
           nginx -g 'daemon off;'"

# FROM php:8.4-fpm-alpine

# RUN apk add --no-cache \
#     nginx supervisor curl zip unzip python3 ffmpeg \
#     libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev \
#     libxml2-dev

# RUN docker-php-ext-configure gd \
#     --with-freetype \
#     --with-jpeg \
#     --with-webp && \
#     docker-php-ext-install pdo pdo_mysql bcmath gd

# COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# WORKDIR /var/www
# COPY . .

# # Copy your custom Nginx configuration
# COPY nginx.conf /etc/nginx/http.d/default.conf

# # FIX: Force-create the required Laravel directories if they don't exist
# RUN mkdir -p /var/www/storage/framework/cache/data \
#     /var/www/storage/framework/sessions \
#     /var/www/storage/framework/views \
#     /var/www/storage/logs \
#     /var/www/bootstrap/cache

# # Setup proper directory permissions
# RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# # Install dependencies and optimize production configurations
# RUN composer install --no-dev --optimize-autoloader
# RUN php artisan config:cache && php artisan route:cache && php artisan view:cache

# EXPOSE 80

# # Run migrations at startup, then run Nginx and PHP-FPM in the foreground together
# CMD ["sh", "-c", "chmod -R 775 /var/www/storage /var/www/bootstrap/cache && php artisan config:clear && php artisan route:clear && php-fpm -D && nginx -g 'daemon off;'"]

