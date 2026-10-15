#!/usr/bin/env bash
#
# Run on the server, in /var/www/isp. Brings the checkout to the requested commit and builds
# it:
#
#   bash deploy/release.sh              # whatever origin/main is now
#   REF=v1.4.0 bash deploy/release.sh   # a tag, a branch or a commit
#
# Everything here is safe to run again: a release that failed halfway is fixed by re-running
# it, not by unpicking it.
#
# The source comes from git rather than from a developer's working tree, so what is running is
# always a commit somebody can look up. A tarball of somebody's laptop cannot be traced back to
# anything, and would carry whatever they had not committed yet.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/isp}"
PHP="${PHP:-/usr/bin/php}"
REMOTE="${REMOTE:-origin}"
REF="${REF:-main}"

cd "$APP_DIR"

echo "==> Source"
if [ ! -d .git ]; then
    echo "  ${APP_DIR} is not a git checkout. Run deploy/provision.sh first." >&2
    exit 1
fi

was="$(git rev-parse --short HEAD 2>/dev/null || echo 'nothing')"

# Local edits on a server are either an emergency somebody forgot to commit or a mistake, and
# a release that silently discards them is how the former becomes the latter. Refuse and say
# what is dirty; `git -C /var/www/isp stash` or a commit clears it deliberately.
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "  REFUSED: ${APP_DIR} has uncommitted changes:" >&2
    git --no-pager diff --stat HEAD >&2
    echo "  Commit, stash or discard them, then run this again." >&2
    exit 1
fi

git fetch --quiet --tags "$REMOTE"

# A fast-forward is not enough: the ref may be a tag or an older commit being rolled back to,
# and both are legitimate things to deploy.
git checkout --quiet --detach "$(git rev-parse --verify "${REMOTE}/${REF}" 2>/dev/null || git rev-parse --verify "$REF")"

now="$(git rev-parse --short HEAD)"
echo "  ${was} -> ${now}  $(git log -1 --pretty=%s)"

echo "==> Refusing a developer's settings"
# Two settings exist for a router sitting on a developer's desk, and both are wrong here.
#
#   WIREGUARD_LOCAL_API_SUBNET  opens every router's API to a whole LAN. A router in a shop
#                               sits on somebody else's LAN, and whoever else is on it is not
#                               us.
#   WIREGUARD_MANAGE_OVER_TUNNEL=false  stops routers being addressed down the tunnel, which
#                               is the only way this platform can reach them at all.
#
# The realistic way either reaches a server is somebody copying .env from their laptop, and
# neither would announce itself: the first quietly widens access, the second quietly points
# provisioning at an address that no longer answers. Cheaper to refuse than to notice.
if [ -f .env ]; then
    for setting in WIREGUARD_LOCAL_API_SUBNET WIREGUARD_MANAGE_OVER_TUNNEL; do
        value="$(grep -E "^${setting}=" .env | tail -1 | cut -d= -f2- | tr -d '"'"'"' \r' || true)"

        case "${setting}:${value}" in
            # Present but empty is the production default spelled out, which is fine.
            WIREGUARD_LOCAL_API_SUBNET:|WIREGUARD_MANAGE_OVER_TUNNEL:|*:true)
                ;;
            *:)
                ;;
            *)
                echo "  REFUSED: ${setting}=${value} is a development setting and must not be set here." >&2
                echo "  Remove it from ${APP_DIR}/.env and run this again." >&2
                exit 1
                ;;
        esac
    done
fi
echo "  none present"

echo "==> Storage skeleton"
# Gitignored, so a fresh clone has none of it. Without these, composer's post-autoload step
# fails with "please provide a valid cache path" — which says nothing about the actual cause.
mkdir -p storage/framework/{cache/data,sessions,views,testing} \
         storage/logs \
         storage/app/{public,private} \
         bootstrap/cache

echo "==> Dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Application key"
# A fresh clone has an empty APP_KEY, and without one nothing boots — every encrypted column
# in this application (router passwords, WireGuard private keys, M-PESA credentials) is keyed
# off it. Generated once and never again: regenerating it would leave every one of those
# columns undecryptable, so this only ever fills a blank.
if grep -qE '^APP_KEY=$' .env; then
    $PHP artisan key:generate --force
    echo "  generated"
else
    echo "  already set, left alone"
fi

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
# Asked for only when it is missing. `storage:link` prints a red ERROR when the link is already
# there, which is the normal state on every deploy after the first — and a release that shouts
# ERROR at a healthy step teaches whoever is watching to stop reading them.
if [ ! -e public/storage ]; then
    $PHP artisan storage:link
fi

echo "==> Database"
# --force because there is no terminal to confirm at. Migrations are additive; nothing
# here drops a column an ISP's data is sitting in.
$PHP artisan migrate --force

# The permission and role catalogue, which is code rather than data: Permissions::all() is the
# source of truth and the table is its copy, so it is reseeded on every release to stay level
# with what was just deployed. Both seeders are updateOrCreate, and outside local DatabaseSeeder
# does nothing else — it creates no accounts and no demo tenants.
#
# Not optional: registration assigns a role, so an unseeded catalogue makes signing up fail.
$PHP artisan db:seed --force

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
# Whichever FPM this box actually runs. Naming a version worked until the server was rebuilt
# on a release carrying a different one, at which point the restart failed silently — `|| true`
# swallowed it — and every deploy served the previous build's cached config until somebody
# restarted it by hand.
fpm="$(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -1)"
if [ -n "$fpm" ]; then
    # Reload, not restart: on a shared box the pool serves other sites, and a restart drops
    # their requests mid-flight. A reload replaces the workers gracefully, which clears opcache
    # just the same. Restart only if the reload is refused.
    systemctl reload "$fpm" 2>/dev/null || systemctl restart "$fpm"
    echo "  reloaded ${fpm}"
else
    echo "  WARNING: no running php-fpm service found; opcache may still hold the old build." >&2
fi
systemctl restart isp-queue.service 2>/dev/null || true
systemctl restart isp-scheduler.service 2>/dev/null || true

# Restarting the scheduler kills whatever its background tasks were in the middle of, and a
# task that is killed never releases its overlap lock. Those locks then block every later run
# until they expire, so a deploy is otherwise a way to silently stop expiring packages and
# chasing payments — which is exactly what it did, unnoticed, until somebody read the log.
#
# Safe to do here: the tasks it unblocks are all written to be run again.
$PHP artisan schedule:clear-cache 2>/dev/null || true

echo "==> Done"
# The commit is printed last because it is the thing worth copying into a ticket: it is the
# whole answer to "what is running".
printf '  %s  %s\n' "$(git rev-parse --short HEAD)" "$(git log -1 --pretty='%s  (%an, %ad)' --date=short)"
$PHP artisan about --only=environment
