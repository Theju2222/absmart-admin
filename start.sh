#!/usr/bin/env bash
set -e

cd /var/www/html

if [ ! -f .env ]; then
  echo "Creating .env from Render environment variables..."

  cat > .env <<EOF
APP_NAME=${APP_NAME}
APP_ENV=${APP_ENV}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG}
APP_URL=${APP_URL}
ASSET_URL=${ASSET_URL}
LOG_CHANNEL=${LOG_CHANNEL}

DB_CONNECTION=${DB_CONNECTION}
DATABASE_URL=${DATABASE_URL}
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
EOF
fi

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

php artisan optimize:clear || true
php artisan storage:link || true
php artisan migrate --force

exec apache2-foreground