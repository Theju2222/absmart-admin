#!/bin/bash
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  composer install --no-dev --optimize-autoloader --no-interaction
fi

php artisan storage:link || true

php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

# Run migrations only on the first deployment
if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force
fi

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf