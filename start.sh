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


chown -R www-data:www-data storage bootstrap/cache public
chmod -R ug+rwX storage bootstrap/cache public


echo "Clearing Laravel runtime caches..."
php artisan optimize:clear || true


echo "Creating public storage link when absent..."
if [ ! -L public/storage ]; then
  php artisan storage:link || true
fi


echo "Checking whether Passport tables exist..."


if php artisan tinker --execute="echo \Illuminate\Support\Facades\Schema::hasTable('oauth_clients') ? 'yes' : 'no';" 2>/dev/null | grep -q "yes"; then
  echo "Passport OAuth tables found."


  if [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
    echo "Generating Laravel Passport encryption keys..."
    php artisan passport:keys --force
  else
    echo "Passport encryption keys already exist."
  fi


  chown www-data:www-data storage/oauth-private.key storage/oauth-public.key
  chmod 600 storage/oauth-private.key
  chmod 644 storage/oauth-public.key


  if ! php artisan tinker --execute="echo \Laravel\Passport\Client::where('personal_access_client', true)->where('revoked', false)->exists() ? 'yes' : 'no';" 2>/dev/null | grep -q "yes"; then
    echo "Creating Laravel Passport personal access client..."


    php artisan passport:client \
      --personal \
      --name="SnapBuy Personal Access Client" \
      --no-interaction
  else
    echo "Active Passport personal access client already exists."
  fi
else
  echo "Passport OAuth tables are not available. Passport setup skipped."
fi


echo "Startup preparation complete."
echo "No migration, database reset, truncation, or seeding was run."


exec apache2-foreground
