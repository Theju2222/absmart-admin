#!/usr/bin/env bash
set -Eeuo pipefail

cd /var/www/html

echo "Starting SnapBuy on Render..."
echo "Writing Laravel .env from Render environment variables..."

cat > .env <<EOF
APP_NAME="${APP_NAME:-SnapBuy}"
APP_ENV="${APP_ENV:-production}"
APP_KEY="${APP_KEY:-}"
APP_DEBUG="${APP_DEBUG:-false}"
APP_URL="${APP_URL:-}"
ASSET_URL="${ASSET_URL:-}"
LOG_CHANNEL="${LOG_CHANNEL:-stack}"

DB_CONNECTION="${DB_CONNECTION:-mysql}"
DB_HOST="${DB_HOST:-}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-}"
DB_USERNAME="${DB_USERNAME:-}"
DB_PASSWORD="${DB_PASSWORD:-}"

CACHE_STORE="${CACHE_STORE:-file}"
SESSION_DRIVER="${SESSION_DRIVER:-file}"
QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
FILESYSTEM_DISK="${FILESYSTEM_DISK:-public}"

INSTALL_MODE="server"
EOF

chown www-data:www-data .env
chmod 640 .env

mkdir -p \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/testing \
  storage/framework/views \
  storage/logs \
  bootstrap/cache \
  public/uploads \
  public/media \
  public/images

chown -R www-data:www-data storage bootstrap/cache public
chmod -R ug+rwX storage bootstrap/cache public

php artisan optimize:clear || true
php artisan permission:cache-reset || true

if [ ! -L public/storage ]; then
  php artisan storage:link || true
fi

echo "Startup preparation complete."
echo "No migration, database reset, seeding, or Passport command was run."
echo "SnapBuy installer will perform first-time installation."

exec apache2-foreground