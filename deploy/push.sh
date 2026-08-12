#!/usr/bin/env bash
#
# Run on the development machine. Sends the working tree to the server and releases it.
#
#   ./deploy/push.sh
#
# There is no git remote, so the source travels as a tar over SSH: what is on this machine is
# what runs. tar rather than rsync because the development machine is Windows, where Git Bash
# ships no rsync — a deploy script that only works on someone else's laptop is not a deploy
# script.
#
# Everything the server builds for itself — vendor, node_modules, the asset build — is
# excluded, along with everything belonging to one machine only. `.env` above all: the server
# holds credentials this machine has never seen.

set -euo pipefail

HOST="${DEPLOY_HOST:-root@solforbs.com}"
KEY="${DEPLOY_KEY:-$HOME/.ssh/solforbs_deploy}"
APP_DIR="${DEPLOY_PATH:-/var/www/isp}"

# An array rather than a string: the key path routinely contains a space on Windows
# ("C:\Users\Pro Codes"), and a plain "$SSH" would split it into two arguments.
SSH=(ssh -i "$KEY" -o BatchMode=yes)

echo "==> Sending source to $HOST:$APP_DIR"

# The exclusion list lives in its own file so deploy/verify.sh can read the same one. What is
# sent and what is checked afterwards must agree on what counts as source, or "identical" means
# nothing.
tar czf - --exclude-from=deploy/excludes.txt \
    . | "${SSH[@]}" "$HOST" "cat > /tmp/isp-src.tgz"

echo "==> Releasing"

"${SSH[@]}" "$HOST" "cd $APP_DIR \
    && tar xzf /tmp/isp-src.tgz \
    && rm -f /tmp/isp-src.tgz \
    && find deploy -name '*.sh' -exec sed -i 's/\r\$//' {} \; \
    && bash deploy/release.sh"
