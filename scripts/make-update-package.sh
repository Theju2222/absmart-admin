#!/usr/bin/env bash
#
# Build a SnapBuy update package (.zip) for the panel's System Updater.
#
# The panel overlays this zip's contents onto the app root (same-path files are
# REPLACED, new files ADDED, untouched files left as-is), then runs
# `migrate --force`. The panel reads the version from the FILE NAME, which MUST
# be:   snapbuy-update-<x.y.z>.zip   — any other name is rejected.
#
# Only the folders that actually change between releases are shipped:
#   app  bootstrap  config  database  public  resources  routes  version.txt
# (vendor, composer files, node_modules, storage, .env, etc. are NOT shipped —
#  those rarely change; ship them manually on the rare release that needs them.)
#
# IMPORTANT before running:
#   1. Bump version.txt to the NEW version (panel rejects <= installed version).
#   2. Rebuild the frontend so public/build is current:  npm run build
#
# Usage:  bash scripts/make-update-package.sh [output.zip]

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [ ! -f version.txt ]; then
  echo "ERROR: version.txt not found at repo root." >&2
  exit 1
fi

VERSION="$(tr -d '[:space:]' < version.txt)"
if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "ERROR: version.txt must contain a semver like 3.1.0 (got '$VERSION')." >&2
  exit 1
fi

# Name MUST match the panel's expected structure: snapbuy-update-<x.y.z>.zip
OUT="${1:-$ROOT/snapbuy-update-${VERSION}.zip}"

if [ ! -d public/build ]; then
  echo "WARNING: public/build missing — run 'npm run build' first (shipping stale/no assets)." >&2
fi

rm -f "$OUT"
echo "Packaging version ${VERSION} -> ${OUT}"

# Whitelist only the folders that change between releases + version.txt.
# version.txt sits at the zip root (also carried by the overlay, but the panel
# uses the file name for the version, not this file).
zip -r -q "$OUT" \
  app bootstrap config database public resources routes version.txt \
  -x 'bootstrap/cache/*.php' \
  -x 'public/storage/*' \
  -x 'public/storage' \
  -x 'public/hot' \
  -x 'config/firebase.json' \
  -x 'storage/*' \
  -x '**/.DS_Store'

echo "Done: $OUT"
ls -lh "$OUT" | awk '{print $5, $9}'
echo "Sanity — version.txt at zip root + name structure:"
unzip -l "$OUT" | grep -E ' version\.txt$' || echo "  !! version.txt NOT at root"
printf '%s' "$(basename "$OUT")" | grep -qE '^snapbuy-update-[0-9]+\.[0-9]+\.[0-9]+\.zip$' \
  && echo "  name OK: $(basename "$OUT")" \
  || echo "  !! name does not match snapbuy-update-x.y.z.zip — panel will reject it"
