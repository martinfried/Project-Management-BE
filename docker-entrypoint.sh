#!/bin/sh
set -e

# Ensure SQLite data directory exists
mkdir -p /var/www/html/var
chmod -R 777 /var/www/html/var

# Run composer install if vendor autoload doesn't exist
if [ ! -f "/var/www/html/vendor/autoload.php" ]; then
    echo "Installing composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Run database initialization and seeding automatically on startup
echo "Initializing database schema & sample data..."
php bin/console app:init-db --seed || true

# Start PHP built-in server on port 8000 using public/index.php router
echo "Starting Symfony server on 0.0.0.0:8000..."
exec php -S 0.0.0.0:8000 -t public public/index.php
