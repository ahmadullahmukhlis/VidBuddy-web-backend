FROM php:8.3-fpm-alpine

# Install system dependencies, Python 3, and FFmpeg for video processing
RUN apk add --no-cache nginx supervisor curl libpng-dev libxml2-dev zip unzip python3 ffmpeg

# Install PHP extensions required by Laravel
RUN docker-php-ext-install pdo pdo_mysql bcmath gd

# Fetch the latest stable Composer binary
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set workspace directory
WORKDIR /var/www

# Copy your backend source code
COPY . .

# Install production dependencies and cache configurations
RUN composer install --no-dev --optimize-autoloader
RUN php artisan config:cache && php artisan route:cache && php artisan view:cache

# Expose port 80 for public web traffic
EXPOSE 80

# Run migrations and launch the internal web engine
CMD ["sh", "-c", "php artisan migrate --force && php-fpm"]
