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
PHP_VER="${PHP_VER:-8.3}"
WG_PORT="${WG_PORT:-51820}"
DEPLOY_KEY="${DEPLOY_KEY:-/root/.ssh/isp_deploy}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root." >&2
    exit 1
fi

say() { printf '\n==> %s\n' "$1"; }

# ---------------------------------------------------------------------------
say "Packages"
# ---------------------------------------------------------------------------
# php-sodium is installed even though the application carries paragonie/sodium_compat: the
# shim exists because XAMPP ships without the extension, and where the real one is present the
# WireGuard key clamping should use it.
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
    nginx \
    "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" "php${PHP_VER}-mbstring" \
    "php${PHP_VER}-xml" "php${PHP_VER}-curl" "php${PHP_VER}-zip" "php${PHP_VER}-bcmath" \
    "php${PHP_VER}-gd" "php${PHP_VER}-intl" "php${PHP_VER}-redis" "php${PHP_VER}-sodium" \
    mysql-client redis-server git curl unzip certbot wireguard >/dev/null

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

if ! ssh -o BatchMode=yes -T git@github.com 2>&1 | grep -q "successfully authenticated"; then
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
DB_PASS=""
if mysql -e "use ${DB_NAME}" 2>/dev/null; then
    echo "  ${DB_NAME} already exists, left alone"
else
    DB_PASS="$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 28)"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    echo "  created ${DB_NAME} and its user"
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
    if [ -z "$DB_PASS" ]; then
        echo "  The database existed but there is no .env, so its password is unknown here." >&2
        echo "  Write ${APP_DIR}/.env yourself, or drop ${DB_NAME} and run this again." >&2
        exit 1
    fi

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

# ---------------------------------------------------------------------------
say "nginx"
# ---------------------------------------------------------------------------
# The committed server block expects a certificate and nginx will not start without one. So
# HTTP only for now; deploy/certificate.sh swaps in the real block once DNS resolves.
mkdir -p /var/www/html/.well-known/acme-challenge

if [ -f "/etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem" ]; then
    install -m 644 "${APP_DIR}/deploy/nginx/${APP_DOMAIN}.conf" "/etc/nginx/sites-available/${APP_DOMAIN}.conf"
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
