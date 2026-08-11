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

echo "==> Storage skeleton"
# Excluded from the transfer because it is one machine's scratch space, so the server builds
# its own. Without these, composer's post-autoload step fails with "please provide a valid
# cache path" — which says nothing about the actual cause.
mkdir -p storage/framework/{cache/data,sessions,views,testing} \
         storage/logs \
         storage/app/{public,private} \
         bootstrap/cache

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
# The web user writes to exactly two trees and owns neither the code nor the .env, so a
# compromised worker cannot rewrite the application it is running.
chown -R root:www-data "$APP_DIR"
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
chmod 640 .env 2>/dev/null || true

# nginx serves public/ as www-data and must be able to traverse it.
find public -type d -exec chmod 2755 {} \;
find public -type f -exec chmod 644 {} \;

echo "==> Restart"
systemctl restart php8.3-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
systemctl restart isp-queue.service 2>/dev/null || true
systemctl restart isp-scheduler.service 2>/dev/null || true

echo "==> Done"
$PHP artisan about --only=environment
