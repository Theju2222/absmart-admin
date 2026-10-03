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

chmod 644 .env

php artisan optimize:clear || true
php artisan storage:link || true

exec apache2-foreground