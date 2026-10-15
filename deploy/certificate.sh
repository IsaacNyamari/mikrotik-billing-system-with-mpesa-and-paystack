#!/usr/bin/env bash
#
# Issue the TLS certificate and switch nginx onto the real server block. Run as root, once
# DNS for the domain points at this machine:
#
#   bash certificate.sh
#
# Separate from provision.sh for one reason: a certificate cannot be issued before the name
# resolves, and that is the one step nobody on this server controls. Running it too early
# burns a Let's Encrypt rate-limit slot for nothing, so this checks first and says so.
#
# Safe to run again. certbot renews rather than reissues when a certificate already exists.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/isp}"
APP_DOMAIN="${APP_DOMAIN:-isp.solforbs.com}"
CERT_EMAIL="${CERT_EMAIL:-}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root." >&2
    exit 1
fi

say() { printf '\n==> %s\n' "$1"; }

# ---------------------------------------------------------------------------
say "Checking DNS before spending an attempt"
# ---------------------------------------------------------------------------
# Let's Encrypt limits failures per account per hour. A name that does not resolve fails every
# time, so it is worth a lookup rather than five wasted attempts and an hour's wait.
resolved="$(getent hosts "$APP_DOMAIN" | awk '{print $1}' | head -1 || true)"

if [ -z "$resolved" ]; then
    echo "  ${APP_DOMAIN} does not resolve." >&2
    echo >&2
    echo "  Create an A record pointing it at this server, wait for it to propagate," >&2
    echo "  then run this again:" >&2
    echo >&2
    echo "      ${APP_DOMAIN}   A   $(curl -fsS --max-time 5 ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')" >&2
    exit 1
fi

mine="$(curl -fsS --max-time 5 ifconfig.me 2>/dev/null || true)"
echo "  ${APP_DOMAIN} -> ${resolved}"

if [ -n "$mine" ] && [ "$resolved" != "$mine" ]; then
    # Not fatal: the name may legitimately sit behind a proxy. But it is almost always a
    # record pointing at the old box, and finding that out now is cheaper than after certbot
    # has failed the HTTP challenge.
    echo "  WARNING: this server believes it is ${mine}, which is not what the name resolves to." >&2
    echo "  If that is deliberate, carry on; if not, fix the record first." >&2
fi

# ---------------------------------------------------------------------------
say "Certificate"
# ---------------------------------------------------------------------------
if [ -f "/etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem" ]; then
    echo "  already issued, renewing if due"
    certbot renew --quiet --cert-name "$APP_DOMAIN" || true
else
    # --webroot rather than --nginx: the nginx plugin rewrites the server block, and this one
    # is committed to the repository. A renewal that edited it would be undone by the next
    # release, silently, three months from now.
    mkdir -p /var/www/html/.well-known/acme-challenge

    email_args=(--register-unsafely-without-email)
    if [ -n "$CERT_EMAIL" ]; then
        email_args=(--email "$CERT_EMAIL")
    fi

    certbot certonly \
        --webroot -w /var/www/html \
        -d "$APP_DOMAIN" \
        --agree-tos \
        --non-interactive \
        "${email_args[@]}"

    echo "  issued"
fi

# ---------------------------------------------------------------------------
say "Switching nginx onto the real block"
# ---------------------------------------------------------------------------
# Same substitution as provision.sh: which PHP-FPM socket exists is a property of the box,
# not of this repository.
# A versioned socket by preference. /run/php/php-fpm.sock is an /etc/alternatives
# symlink that follows whichever PHP is the system default, so a later version
# becoming default would silently move this site onto it.
socket="$(ls /run/php/php[0-9]*-fpm.sock 2>/dev/null | sort -V | tail -1)"
socket="${socket:-/run/php/php8.3-fpm.sock}"
sed "s|unix:/run/php/php[0-9.]*-fpm\.sock|unix:${socket}|g"     "${APP_DIR}/deploy/nginx/${APP_DOMAIN}.conf"     > "/etc/nginx/sites-available/${APP_DOMAIN}.conf"
chmod 644 "/etc/nginx/sites-available/${APP_DOMAIN}.conf"

# `http2 on;` is nginx 1.25.1 and later. Ubuntu 24.04 ships 1.24, which rejects it — and a
# block that fails `nginx -t` sitting in sites-enabled breaks the next reload for every other
# site on the box. On an older nginx the same thing is said the older way.
nginx_ver="$(nginx -v 2>&1 | sed -n 's|.*nginx/\([0-9.]*\).*|\1|p')"
if [ -n "$nginx_ver" ] && [ "$(printf '%s\n' "$nginx_ver" 1.25.1 | sort -V | head -1)" != "1.25.1" ]; then
    sed -i -E -e 's/^(\s*)listen (\[::\]:)?443 ssl;/\1listen \2443 ssl http2;/' -e '/^\s*http2 on;/d' \
        "/etc/nginx/sites-available/${APP_DOMAIN}.conf"
    echo "  nginx ${nginx_ver}: http2 set on the listen line"
fi
echo "  fpm socket: ${socket}"
ln -sfn "/etc/nginx/sites-available/${APP_DOMAIN}.conf" "/etc/nginx/sites-enabled/${APP_DOMAIN}.conf"

nginx -t
systemctl reload nginx
echo "  reloaded"

# ---------------------------------------------------------------------------
say "Renewal"
# ---------------------------------------------------------------------------
# certbot ships its own timer on Ubuntu. The reload hook is what makes a renewal take effect
# without anybody noticing it happened.
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat >/etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh <<'HOOK'
#!/usr/bin/env bash
systemctl reload nginx
HOOK
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

systemctl enable --now certbot.timer >/dev/null 2>&1 || true
echo "  certbot.timer enabled, nginx reloads on renewal"

cat <<DONE

==> https://${APP_DOMAIN} is live.

Last step, if you have not already:

    cd ${APP_DIR} && php artisan platform:admin

DONE
