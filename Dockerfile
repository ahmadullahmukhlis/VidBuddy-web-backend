FROM php:8.4-fpm-alpine

# =========================
# System dependencies
# =========================
RUN apk add --no-cache \
    nginx supervisor curl zip unzip git bash \
    ffmpeg \
    python3 \
    libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev \
    libxml2-dev oniguruma-dev

# =========================
# Install yt-dlp (FIXED + PERMISSION SAFE)
# =========================
RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp && \
    chmod 755 /usr/local/bin/yt-dlp && \
    ls -lah /usr/local/bin/yt-dlp && \
    /usr/local/bin/yt-dlp --version

# =========================
# PHP extensions
# =========================
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp && \
    docker-php-ext-install \
    pdo \
    pdo_mysql \
    bcmath \
    gd \
    mbstring \
    xml \
    fileinfo

# =========================
# Composer
# =========================
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .

# =========================
# Nginx config
# =========================
COPY nginx.conf /etc/nginx/http.d/default.conf

# =========================
# Laravel folders
# =========================
RUN mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/temp \
    storage/logs \
    bootstrap/cache && \
    mkdir -p database && touch database/database.sqlite

# =========================
# Environment
# =========================
ENV TMPDIR=/var/www/storage/framework/temp
ENV APP_DEBUG=true
ENV LOG_LEVEL=debug

# =========================
# Permissions (CRITICAL FIX FOR RENDER)
# =========================
RUN addgroup -g 1000 www && adduser -G www -u 1000 -D www && \
    chown -R www:www /var/www && \
    chmod -R 777 storage bootstrap/cache

# =========================
# Install Laravel dependencies
# =========================
RUN composer install --no-interaction --no-dev --optimize-autoloader

EXPOSE 80

# =========================
# Start services
# =========================
CMD sh -c "\
    echo '--- STARTING LARAVEL ---' && \
    chmod -R 777 storage bootstrap/cache && \
    php artisan config:clear || true && \
    php artisan cache:clear || true && \
    php artisan route:clear || true && \
    php artisan view:clear || true && \
    php-fpm -D && \
    nginx -g 'daemon off;'"
