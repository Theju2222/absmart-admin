#!/usr/bin/env bash
#
# Build a FULL SnapBuy package (.zip) to hand to a client for a fresh install —
# the whole project, minus dev-only / machine-specific / sensitive files.
#
# EXCLUDED (per delivery policy):
#   node_modules/            — client runs `npm install` if they build assets
#   .env                     — secrets / machine config (ship .env.example instead)
#   config/firebase.json     — private service-account credentials
#   storage/installed        — install marker (so the client's installer runs)
#   storage/app/public/*     — uploaded media / images (client starts clean; the
#                              folder itself ships via its .gitignore so storage:link works)
#   storage/app/pwa-icons/*  — icons rendered from OUR logo; regenerated on demand
#   public/hot               — vite dev-server marker; its presence makes the panel
#                              load assets from localhost:5173 and show nothing
#   runtime junk             — logs, framework cache/sessions/views, debugbar,
#                              bootstrap/cache, .git, .DS_Store, existing zips
#
# KEPT: vendor/ (so it runs without composer), public/build (built assets),
#       .env.example, everything else.
#
# BEFORE running: rebuild the frontend so public/build is current:  npm run build
#
# Usage:  bash scripts/make-client-package.sh [output.zip]

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="unknown"
if [ -f version.txt ]; then
  VERSION="$(tr -d '[:space:]' < version.txt)"
fi

OUT="${1:-$ROOT/admin_panel_v${VERSION#v}.zip}"

if [ ! -d public/build ]; then
  echo "WARNING: public/build missing — run 'npm run build' first (shipping stale/no assets)." >&2
fi
if [ ! -d vendor ]; then
  echo "WARNING: vendor/ missing — run 'composer install' first (client package won't run out of the box)." >&2
fi

rm -f "$OUT"
echo "Packaging FULL client build ${VERSION} -> ${OUT}"

# Zip the whole project, excluding the delivery-policy paths + runtime junk.
zip -r -q "$OUT" . \
  -x '.git/*' \
  -x '.vscode/*' \
  -x '.cursor/*' \
  -x '.claude/*' \
  -x '.well-known/*' \
  -x 'node_modules/*' \
  -x '.env.local' \
  -x '.env.backup' \
  -x '.env.testing' \
  -x 'config/firebase.json' \
  -x 'public/hot' \
  -x 'public/storage' \
  -x 'public/storage/*' \
  -x 'storage/installed' \
  -x 'storage/installed/*' \
  -x 'storage/app/public/[!.]*' \
  -x 'storage/app/pwa-icons/[!.]*' \
  -x 'storage/*.key' \
  -x 'storage/oauth-private.key' \
  -x 'storage/oauth-public.key' \
  -x 'storage/logs/[!.]*' \
  -x 'storage/debugbar/[!.]*' \
  -x 'storage/framework/cache/data/[!.]*' \
  -x 'storage/framework/sessions/[!.]*' \
  -x 'storage/framework/views/[!.]*' \
  -x 'bootstrap/cache/[!.]*' \
  -x 'snapbuy-*.zip' \
  -x 'admin_panel_*.zip' \
  -x "$(basename "$OUT")" \
  -x '.DS_Store' \
  -x '*/.DS_Store'

echo "Done: $OUT"
ls -lh "$OUT" | awk '{print $5, $9}'

# Compare against the archive's path list, not a substring of the whole listing line:
# " .env" also matches ".env.example", which is shipped on purpose.
PATHS="$(unzip -Z1 "$OUT")"
leaked=0

echo "Sanity — excluded paths must NOT appear:"
for p in '.env' 'config/firebase.json' 'storage/installed' 'public/hot'; do
  if printf '%s\n' "$PATHS" | grep -qxF "$p"; then
    echo "  !! LEAKED: $p is in the zip"
    leaked=1
  else
    echo "  ok: $p excluded"
  fi
done
for prefix in 'node_modules/' 'storage/app/public/' 'storage/app/pwa-icons/'; do
  # The folder and its .gitignore placeholder are meant to ship; only real files leak.
  n="$(printf '%s\n' "$PATHS" | grep -E "^${prefix}.+" | grep -vE "^${prefix}\.gitignore$" | grep -c . || true)"
  if [ "$n" -gt 0 ]; then
    echo "  !! LEAKED: $n file(s) under $prefix"
    leaked=1
  else
    echo "  ok: $prefix empty (folder kept)"
  fi
done

if [ "$leaked" -ne 0 ]; then
  echo "Package NOT safe to hand over — fix the exclusions above." >&2
  exit 1
fi
