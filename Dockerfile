FROM php:8.2-cli-alpine

# Install system dependencies & SQLite libraries
RUN apk add --no-cache \
    git \
    unzip \
    curl \
    sqlite \
    sqlite-dev \
    libzip-dev \
    icu-dev \
    && docker-php-ext-install \
    pdo \
    pdo_sqlite \
    intl \
    zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install Composer dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Set permissions and create required directories
RUN mkdir -p /var/www/html/var /var/www/html/config/serializer \
    && chmod +x /var/www/html/docker-entrypoint.sh \
    && chmod -R 777 /var/www/html/var

# Expose port
EXPOSE 8000

# Entrypoint script
ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
