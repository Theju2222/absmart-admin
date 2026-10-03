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

if [ -n "${PASSPORT_PRIVATE_KEY:-}" ] && [ -n "${PASSPORT_PUBLIC_KEY:-}" ]; then
  echo "Restoring Laravel Passport keys from environment variables..."

  printf '%s\n' "$PASSPORT_PRIVATE_KEY" > storage/oauth-private.key
  printf '%s\n' "$PASSPORT_PUBLIC_KEY" > storage/oauth-public.key

  chown www-data:www-data storage/oauth-private.key storage/oauth-public.key
  chmod 600 storage/oauth-private.key
  chmod 644 storage/oauth-public.key
else
  echo "Passport key variables are not set. Passport key setup skipped."
fi

if php artisan tinker --execute="echo \Illuminate\Support\Facades\Schema::hasTable('oauth_clients') ? 'yes' : 'no';" | grep -q "yes"; then
  echo "Passport OAuth tables exist."

  if ! php artisan tinker --execute="echo \Laravel\Passport\Client::where('personal_access_client', true)->where('revoked', false)->exists() ? 'yes' : 'no';" | grep -q "yes"; then
    echo "Creating Laravel Passport personal access client..."

    php artisan passport:client \
      --personal \
      --name="ABSmart Personal Access Client" \
      --no-interaction
  fi
else
  echo "Passport OAuth tables do not exist yet. Skipping Passport client setup."
fi

php artisan optimize:clear || true
php artisan storage:link || true

exec apache2-foreground