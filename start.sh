#!/usr/bin/env bash
set -e

cd /var/www/html

echo "Writing .env from Render environment variables..."

cat > .env <<EOF
APP_NAME="${APP_NAME}"
APP_ENV=${APP_ENV}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG}
APP_URL=${APP_URL}
ASSET_URL=${ASSET_URL}
LOG_CHANNEL=${LOG_CHANNEL}

DB_CONNECTION=${DB_CONNECTION}
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD="${DB_PASSWORD}"
EOF

chown www-data:www-data .env
chmod 664 .env

mkdir -p \
  storage/app/public \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache \
  public/uploads \
  public/media \
  public/images

chown -R www-data:www-data storage bootstrap/cache public
chmod -R 775 storage bootstrap/cache public

if [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
  echo "Generating Laravel Passport keys..."
  php artisan passport:keys --force
fi

chown -R www-data:www-data storage
chmod 600 storage/oauth-private.key
chmod 644 storage/oauth-public.key

if ! php artisan tinker --execute="echo \Laravel\Passport\Client::where('personal_access_client', true)->exists() ? 'yes' : 'no';" | grep -q "yes"; then
  echo "Creating Laravel Passport personal access client..."
  php artisan passport:install --force
fi

php artisan optimize:clear || true
php artisan storage:link || true

exec apache2-foreground