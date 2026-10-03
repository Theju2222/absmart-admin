#!/usr/bin/env bash
set -e

cd /var/www/html

echo "Installing Composer dependencies..."
composer install \
  --no-dev \
  --no-interaction \
  --prefer-dist \
  --optimize-autoloader

echo "Preparing Laravel..."
php artisan optimize:clear || true
php artisan storage:link || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "Running database migrations..."
  php artisan migrate --force
fi

echo "Starting Nginx and PHP-FPM..."
exec /start.sh