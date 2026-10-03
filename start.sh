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
EOF

chown www-data:www-data .env
chmod 640 .env

echo "Creating Laravel runtime directories..."

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

echo "Applying write permissions..."

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

chown -R www-data:www-data public/uploads public/media public/images
chmod -R ug+rwX public/uploads public/media public/images

echo "Clearing Laravel runtime caches..."
php artisan optimize:clear || true

echo "Creating public storage link when absent..."
if [ ! -L public/storage ]; then
  php artisan storage:link || true
fi

# ============================================================
# ONE-TIME MIGRATION BLOCK
# Remove this entire block immediately after a successful deploy.
# ============================================================
echo "Running one-time SnapBuy database migrations..."
php artisan migrate --force
echo "One-time database migrations completed."
# ============================================================

echo "Startup preparation complete."
echo "Apache is starting..."

exec apache2-foreground