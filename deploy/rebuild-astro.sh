#!/bin/bash
# Rebuilds the Astro public site and publishes it.
#
# Install on the server:  cp deploy/rebuild-astro.sh /var/www/alodium/rebuild-astro.sh
#                         chmod +x /var/www/alodium/rebuild-astro.sh
# Called by App\Jobs\RebuildAstroJob as the queue worker's user (www-data).
# Configure in Laravel's .env:  ASTRO_REBUILD_SCRIPT=/var/www/alodium/rebuild-astro.sh

set -euo pipefail

APP_DIR=/var/www/alodium
LOG="$APP_DIR/storage/logs/astro-rebuild.log"

trap 'echo "Rebuild FAILED at $(date)" >> "$LOG"' ERR
echo "Rebuild started at $(date)" >> "$LOG"

cd "$APP_DIR/astro"

# Production build: reads .env.production (production API_URL, SITE_URL).
NODE_ENV=production npm run build

# Only reached if the build succeeded, so a failed build never replaces the live site.
# --delete removes pages of posts that were unpublished or deleted
# (cp -r would leave them online forever).
rsync -a --delete dist/ "$APP_DIR/astro-public/"

echo "Rebuild complete at $(date)" >> "$LOG"
