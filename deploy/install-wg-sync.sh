#!/usr/bin/env bash
#
# Lets the platform load WireGuard peers without an operator opening a terminal.
#
# Run once, as root, on the management server. It installs the wrapper and the single sudoers
# line that permits the web user to run that wrapper and nothing else.
#
# Kept out of release.sh on purpose: granting a privilege is not something that should happen
# quietly as part of every deploy.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/isp}"
BIN=/usr/local/bin/isp-wg-sync

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root — it installs a sudoers rule." >&2
    exit 1
fi

install -m 0755 -o root -g root "$APP_DIR/deploy/bin/isp-wg-sync" "$BIN"

# One command, no arguments, no wildcards. www-data cannot use this to run anything else.
cat > /etc/sudoers.d/isp-wg-sync <<SUDOERS
# Lets the platform add WireGuard peers for routers it already knows about.
www-data ALL=(root) NOPASSWD: $BIN
SUDOERS

chmod 0440 /etc/sudoers.d/isp-wg-sync

# A malformed sudoers file locks everyone out of sudo, so it is checked before being trusted.
visudo -cf /etc/sudoers.d/isp-wg-sync

echo "Installed. Add this to $APP_DIR/.env and re-cache the config:"
echo
echo "    WIREGUARD_SYNC_COMMAND=\"sudo -n $BIN\""
