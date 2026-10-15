#!/usr/bin/env bash
#
# Turn a bare Ubuntu server into one that can run this platform. Run once, as root:
#
#   bash provision.sh
#
# Safe to run again. Every step checks for what it would create and leaves it alone if it is
# already there, so a run that stopped halfway is fixed by running it again rather than by
# unpicking it.
#
# It deliberately stops short of two things:
#
#   * the TLS certificate, which cannot be issued until DNS resolves — deploy/certificate.sh
#   * the first administrator, which is created interactively so nobody writes a password down
#
# The box is shared with other sites. Nothing here touches them: it adds one nginx server
# block, one database, two systemd units and one WireGuard interface, and changes no global
# configuration.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/isp}"
APP_DOMAIN="${APP_DOMAIN:-isp.solforbs.com}"
REPO="${REPO:-git@github.com:IsaacNyamari/mikrotik-billing-system-with-mpesa-and-paystack.git}"
BRANCH="${BRANCH:-main}"
DB_NAME="${DB_NAME:-isp_platform}"
DB_USER="${DB_USER:-isp_platform}"
WG_PORT="${WG_PORT:-51820}"

# Whatever this release of Ubuntu actually ships, rather than whatever it shipped when this was
# written. Pinning 8.3 meant 26.04 — which carries 8.5 and nothing older — failed at the first
# apt-get with a package name that does not exist, which reads as a broken script rather than a
# wrong assumption.
if [ -z "${PHP_VER:-}" ]; then
    if command -v php >/dev/null 2>&1; then
        PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    else
        PHP_VER="$(apt-cache search --names-only '^php[0-9.]+-fpm$' 2>/dev/null |
            sed -n 's/^php\([0-9.]*\)-fpm .*/\1/p' | sort -V | tail -1)"
    fi
fi

if [ -z "$PHP_VER" ]; then
    echo "Could not work out which PHP this system has. Set PHP_VER and run again." >&2
    exit 1
fi
DEPLOY_KEY="${DEPLOY_KEY:-/root/.ssh/isp_deploy}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root." >&2
    exit 1
fi

say() { printf '\n==> %s\n' "$1"; }

# ---------------------------------------------------------------------------
say "Packages"
# ---------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

# Asked for by name, but only the ones this release actually packages. Which extensions ship
# as separate packages and which are built into php-common differs between releases — 8.5
# compiles sodium in, so `php8.5-sodium` does not exist and asking for it aborts the whole
# install over an extension that is already present.
#
# What matters is the loaded extension, not the package, so the list is filtered here and the
# result is checked against `php -m` below.
wanted=(nginx mysql-client redis-server git curl unzip certbot wireguard)
for ext in fpm cli mysql mbstring xml curl zip bcmath gd intl redis sodium; do
    wanted+=("php${PHP_VER}-${ext}")
done

available=()
skipped=()
for pkg in "${wanted[@]}"; do
    if apt-cache show "$pkg" >/dev/null 2>&1; then
        available+=("$pkg")
    else
        skipped+=("$pkg")
    fi
done

apt-get install -y -qq "${available[@]}" >/dev/null

if [ ${#skipped[@]} -gt 0 ]; then
    echo "  not packaged on this release: ${skipped[*]}"
fi

# A database server only when the box has none. A shared box usually already runs MySQL or
# MariaDB for its other sites, and installing a second server beside it would fight it for
# port 3306; a fresh one has nothing, and the database step below would fail with
# "can't connect" — a message that sends people looking at passwords rather than packages.
if ! systemctl list-unit-files 2>/dev/null | grep -qE '^(mysql|mariadb|mysqld)\.service'; then
    apt-get install -y -qq mysql-server >/dev/null
    systemctl enable --now mysql >/dev/null 2>&1 || true
    echo "  mysql-server installed"
fi

if ! command -v composer >/dev/null 2>&1; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

if ! command -v node >/dev/null 2>&1; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null
    apt-get install -y -qq nodejs >/dev/null
fi

printf '  php %s, node %s\n' "$(php -r 'echo PHP_VERSION;')" "$(node -v)"

# The package names were a means; these are the end. Checked against what PHP reports rather
# than against what apt installed, so an extension that arrived built in counts and one that
# silently failed to install does not.
missing=()
for ext in mbstring dom curl zip bcmath gd intl pdo_mysql openssl tokenizer fileinfo redis; do
    php -m | grep -qix "$ext" || missing+=("$ext")
done

if [ ${#missing[@]} -gt 0 ]; then
    echo "  Missing PHP extensions: ${missing[*]}" >&2
    echo "  Install them and run this again; the application will not boot without them." >&2
    exit 1
fi
echo "  extensions present"

# ---------------------------------------------------------------------------
say "Deploy key for the private repository"
# ---------------------------------------------------------------------------
# A read-only deploy key rather than an account token: it is scoped to this one repository, so
# a server that is compromised cannot reach anything else the account owns.
mkdir -p /root/.ssh
chmod 700 /root/.ssh

if [ ! -f "$DEPLOY_KEY" ]; then
    ssh-keygen -t ed25519 -N '' -C "isp-deploy@$(hostname)" -f "$DEPLOY_KEY" >/dev/null
    echo "  generated"
else
    echo "  already present"
fi

if ! grep -q "github.com" /root/.ssh/known_hosts 2>/dev/null; then
    ssh-keyscan -t ed25519 github.com >>/root/.ssh/known_hosts 2>/dev/null
fi

cat >/root/.ssh/config <<CONF
Host github.com
    IdentityFile ${DEPLOY_KEY}
    IdentitiesOnly yes
CONF
chmod 600 /root/.ssh/config

# Captured, then matched, rather than piped straight into grep. `ssh -T` to GitHub always exits
# 1 — it authenticates you and then closes, because there is no shell to give — and under
# `set -o pipefail` that exit code becomes the pipeline's, however well grep did. The check
# therefore reported a working deploy key as a missing one, and said so convincingly.
github_auth="$(ssh -o BatchMode=yes -T git@github.com 2>&1 || true)"

if ! grep -q "successfully authenticated" <<<"$github_auth"; then
    echo "  ${github_auth}" | head -3
    echo
    echo "  This server cannot read the repository yet. Add the public key below at:"
    echo
    echo "      https://github.com/IsaacNyamari/mikrotik-billing-system-with-mpesa-and-paystack/settings/keys"
    echo
    echo "  Leave \"Allow write access\" unticked — the server only ever reads."
    echo
    cat "${DEPLOY_KEY}.pub"
    echo
    echo "  Then run this script again. Nothing past this point has been done."
    exit 1
fi
echo "  github reachable"

# ---------------------------------------------------------------------------
say "Database"
# ---------------------------------------------------------------------------
# The password is generated here and written only into .env. Nobody needs to know it, and a
# password nobody knows is one nobody can paste into a support ticket.
#
# Nothing here drops anything. The database may already exist — somebody creating it by hand
# before running this is the ordinary case, not a mistake — so this creates what is missing and
# sets the credentials the .env below will carry. Tables and rows are never touched.
DB_PASS=""
if [ -f "${APP_DIR}/.env" ]; then
    # An .env already holds a password this script does not know. Resetting the user's password
    # would lock the running application out of its own database.
    echo "  .env already present, leaving the database and its user alone"
else
    DB_PASS="$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 28)"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    tables="$(mysql -N -B -e "select count(*) from information_schema.tables where table_schema='${DB_NAME}';" 2>/dev/null || echo 0)"
    echo "  ${DB_NAME} ready (${tables} existing table(s)), user ${DB_USER} set"
fi

# ---------------------------------------------------------------------------
say "Source"
# ---------------------------------------------------------------------------
mkdir -p "$(dirname "$APP_DIR")"
if [ -d "${APP_DIR}/.git" ]; then
    git -C "$APP_DIR" remote set-url origin "$REPO"
    git -C "$APP_DIR" fetch --quiet origin "$BRANCH"
    echo "  already a checkout, fetched"
else
    git clone --quiet --branch "$BRANCH" "$REPO" "$APP_DIR"
    echo "  cloned"
fi

# ---------------------------------------------------------------------------
say "Environment"
# ---------------------------------------------------------------------------
# Written once and never touched again. It is the one file on this server that the repository
# does not own, and a release that rewrote it would take the database password with it.
if [ -f "${APP_DIR}/.env" ]; then
    echo "  .env already present, left alone"
else
    cat >"${APP_DIR}/.env" <<ENV
APP_NAME="ISP Platform"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://${APP_DOMAIN}
APP_TIMEZONE=Africa/Nairobi

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}

# Redis rather than the database driver. The scheduler's overlap locks and the portal's
# per-payment locks are both taken far more often than they are written through.
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
# The box is shared, so this installation keeps to its own keyspace and its own databases.
REDIS_PREFIX=isp_
REDIS_CACHE_DB=3
REDIS_DB=4

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@${APP_DOMAIN}"
MAIL_FROM_NAME="\${APP_NAME}"

# The management tunnel. WIREGUARD_PUBLIC_KEY is filled in below.
WIREGUARD_ENDPOINT=${APP_DOMAIN}
WIREGUARD_PORT=${WG_PORT}
WIREGUARD_PUBLIC_KEY=
WIREGUARD_SUBNET=10.8.0.0/24
WIREGUARD_SERVER_ADDRESS=10.8.0.1

# Each ISP enters its own M-PESA or Paystack credentials in the console. These are the
# platform's own, for collecting what ISPs pay us, and stay off until that is switched on.
PLATFORM_MPESA_ENABLED=false
PLATFORM_PAYSTACK_ENABLED=false
ENV
    echo "  written"
fi

chown root:www-data "${APP_DIR}/.env"
chmod 640 "${APP_DIR}/.env"

# ---------------------------------------------------------------------------
say "WireGuard"
# ---------------------------------------------------------------------------
# Management only: no forwarding and no NAT, so the tunnel never becomes an ISP's accidental
# transit provider.
if [ ! -f /etc/wireguard/wg0.conf ]; then
    (
        umask 077
        wg genkey >/etc/wireguard/server.key
        wg pubkey </etc/wireguard/server.key >/etc/wireguard/server.pub

        cat >/etc/wireguard/wg0.conf <<WG
# Peers are appended by \`php artisan wireguard:peers --apply\`. Do not edit them by hand.
[Interface]
Address = 10.8.0.1/24
ListenPort = ${WG_PORT}
PrivateKey = $(cat /etc/wireguard/server.key)
WG
    )
    echo "  interface created"
else
    echo "  already configured"
fi

systemctl enable --now wg-quick@wg0 >/dev/null 2>&1 || systemctl restart wg-quick@wg0

if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow "${WG_PORT}/udp" >/dev/null || true
    echo "  ufw opened ${WG_PORT}/udp"
fi

# The application writes this public key into every router's setup script, so a router can
# never come up without it.
if grep -q '^WIREGUARD_PUBLIC_KEY=$' "${APP_DIR}/.env"; then
    sed -i "s|^WIREGUARD_PUBLIC_KEY=$|WIREGUARD_PUBLIC_KEY=$(cat /etc/wireguard/server.pub)|" "${APP_DIR}/.env"
fi
echo "  public key in .env"

# The committed block names a PHP-FPM socket, and which one exists depends on the release of
# Ubuntu underneath rather than on anything in this repository. Substituted on the way in, so
# the committed file stays a valid config to read while the installed one matches the box.
install_site() {
    local socket
    # A versioned socket by preference. /run/php/php-fpm.sock is an /etc/alternatives
    # symlink that follows whichever PHP is the system default, so a later version
    # becoming default would silently move this site onto it.
    socket="$(ls /run/php/php[0-9]*-fpm.sock 2>/dev/null | sort -V | tail -1)"
    socket="${socket:-/run/php/php${PHP_VER}-fpm.sock}"

    sed "s|unix:/run/php/php[0-9.]*-fpm\.sock|unix:${socket}|g"         "${APP_DIR}/deploy/nginx/${APP_DOMAIN}.conf"         > "/etc/nginx/sites-available/${APP_DOMAIN}.conf"
    chmod 644 "/etc/nginx/sites-available/${APP_DOMAIN}.conf"
    echo "  fpm socket: ${socket}"
}

# ---------------------------------------------------------------------------
say "nginx"
# ---------------------------------------------------------------------------
# The committed server block expects a certificate and nginx will not start without one. So
# HTTP only for now; deploy/certificate.sh swaps in the real block once DNS resolves.
mkdir -p /var/www/html/.well-known/acme-challenge

if [ -f "/etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem" ]; then
    install_site
    echo "  certificate already present, installed the real block"
else
    cat >"/etc/nginx/sites-available/${APP_DOMAIN}.conf" <<CONF
# Temporary. deploy/certificate.sh replaces this with the committed block.
server {
    listen 80;
    listen [::]:80;
    server_name ${APP_DOMAIN};

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/html;
        default_type "text/plain";
    }

    location / {
        default_type "text/plain";
        return 200 "Awaiting certificate.\n";
    }
}
CONF
    echo "  temporary HTTP block installed"
fi

ln -sfn "/etc/nginx/sites-available/${APP_DOMAIN}.conf" "/etc/nginx/sites-enabled/${APP_DOMAIN}.conf"
nginx -t >/dev/null
systemctl reload nginx
echo "  reloaded"

# ---------------------------------------------------------------------------
say "Services"
# ---------------------------------------------------------------------------
install -m 644 "${APP_DIR}/deploy/systemd/isp-scheduler.service" /etc/systemd/system/
install -m 644 "${APP_DIR}/deploy/systemd/isp-queue.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable isp-scheduler.service isp-queue.service >/dev/null
echo "  scheduler and queue enabled"

# ---------------------------------------------------------------------------
say "First release"
# ---------------------------------------------------------------------------
bash "${APP_DIR}/deploy/release.sh"

cat <<DONE

==> Provisioned.

Still to do, in this order:

  1. Point DNS at this server:
         ${APP_DOMAIN}   A   $(curl -fsS --max-time 5 ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')

  2. Issue the certificate:
         bash ${APP_DIR}/deploy/certificate.sh

  3. Create an administrator (it asks for the password rather than knowing it):
         cd ${APP_DIR} && php artisan platform:admin

DONE
