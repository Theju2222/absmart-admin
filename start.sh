#!/usr/bin/env bash
set -e

cd /var/www/html

php artisan optimize:clear || true
php artisan storage:link || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "Running database migrations..."
  php artisan migrate --force
fi

exec /start.sh