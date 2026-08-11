#!/usr/bin/env bash
#
# Run on the server, in /var/www/isp, after new source has landed.
#
# Everything here is safe to run again: a release that failed halfway is fixed by
# re-running it, not by unpicking it.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/isp}"
PHP="${PHP:-/usr/bin/php}"

cd "$APP_DIR"

echo "==> Dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Assets"
# The admin UI is a Vite build. The captive portal deliberately needs none of it, so a
# failed asset build never takes the portal down with it.
if [ -f package-lock.json ]; then
    npm ci --no-audit --no-fund
else
    npm install --no-audit --no-fund
fi
npm run build

echo "==> Storage link"
$PHP artisan storage:link || true

echo "==> Database"
# --force because there is no terminal to confirm at. Migrations are additive; nothing
# here drops a column an ISP's data is sitting in.
$PHP artisan migrate --force

echo "==> Caches"
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

echo "==> Permissions"
# The web user writes to exactly two trees and must own neither the code nor the .env.
chown -R www-data:www-data storage bootstrap/cache public/storage 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache

echo "==> Restart"
systemctl restart php8.3-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
systemctl restart isp-queue.service 2>/dev/null || true
systemctl restart isp-scheduler.service 2>/dev/null || true

echo "==> Done"
$PHP artisan about --only=environment
