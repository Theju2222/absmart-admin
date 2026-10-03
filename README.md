# SnapBuy - Hyperlocal Quick Commerce & eCommerce Platform with Multi-Store & Multi-Country

SnapBuy is a Laravel 12 + Vue 3 admin panel and REST API powering a multi-country,
multi-language online store that supports both **ecommerce** (item-wise fulfilment)
and **quick commerce** (single-store, order-wise) sales channels.

- **Author:** WRTeam · support@wrteam.in · https://wrteam.in
- **Support:** support@wrteam.in

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP (Laravel 12), Laravel Passport (OAuth) |
| Admin frontend | Vue 3 (Options API) + Bootstrap-Vue-Next, built with Vite |
| Database | MySQL 8 |
| Realtime | Laravel Reverb / Pusher (chat, notifications) |
| Storage | Local disk (`storage/app/public` via `storage:link`) |

---

## Requirements

- **PHP ≥ 8.3** with extensions: `zip`, `curl`,
  `mbstring`, `openssl`, `pdo_mysql`, `gd`, `fileinfo`
- **Composer** 2.x
- **Node.js** ≥ 18 + npm (only to build the frontend)
- **MySQL** ≥ 8.0

---

## Installation

### Option A — Web installer (recommended)
1. Upload the code and point the web root at `public/`.
2. Copy `.env.example` to `.env` (leave `APP_KEY=` empty — it is generated on first run).
3. Visit the site; the installer wizard checks requirements, collects DB + admin
   credentials, runs migrations/seeders, installs Passport keys, and links storage.

### Option B — Manual (CLI)
```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan passport:install
php artisan storage:link
npm install && npm run build
```

---

## Configuration (.env highlights)

| Key | Purpose |
|-----|---------|
| `APP_KEY` | Encryption key — auto-generated on first boot if empty |
| `DB_*` | Database connection |
| `DEMO_MODE` | `1` = read-only demo (write routes blocked for non-super-admin) |
| `REVERB_*` / `PUSHER_*` | Realtime broadcast credentials (chat / notifications) |
| `WEBSITE_URL` | Public storefront URL |

> Read config via `config('app.demo_mode')` (not `env()`) so it stays correct when
> config is cached.

---

## Building the Frontend

```bash
npm run dev     # local development (HMR)
npm run build   # production assets into public/build
```

---

## Updating

Updates are applied from the admin panel: **Settings → System Updater**, by uploading
an official update package.

- **Package name must be** `snapbuy-update-<x.y.z>.zip` — the version is read from the
  file name and must be strictly newer than the installed version.
- The panel takes the app offline, overlays the package onto the app root, runs
  `php artisan migrate --force`, clears caches, and updates `version.txt`.
- Ship **all** reference-data changes (permissions, templates, settings) as **idempotent
  migrations** — install seeders run on fresh installs only, never on update.

Build a package with:
```bash
bash scripts/make-update-package.sh   # bump version.txt + npm run build first
```

---

## Deployment Notes

- Never commit/deploy a stale `bootstrap/cache/config.php`; run `php artisan optimize:clear`
  (or delete it) after uploading code so the server reads its own `.env`.
- Keep `storage/` and `bootstrap/cache/` writable by the web user
  (`chown -R www:www storage bootstrap/cache`); a permission error there breaks
  push-notification token caching and file writes.
- Composer resolves for PHP 8.3 (`config.platform.php` in `composer.json`); run
  `composer install` on a PHP 8.3 host so `platform_check` matches.

---

## License

Proprietary — © WRTeam. Redistribution requires a valid purchase/license.
