#!/usr/bin/env bash
#
# Run on the development machine. Sends the working tree to the server and releases it.
#
#   ./deploy/push.sh
#
# There is no git remote, so rsync is the transport: what is on this machine is what runs.
# Everything the server builds for itself — vendor, node_modules, the asset build — is
# excluded, along with everything that belongs to one machine only.

set -euo pipefail

HOST="${DEPLOY_HOST:-root@solforbs.com}"
KEY="${DEPLOY_KEY:-$HOME/.ssh/solforbs_deploy}"
APP_DIR="${DEPLOY_PATH:-/var/www/isp}"

SSH="ssh -i $KEY -o BatchMode=yes"

echo "==> Sending source to $HOST:$APP_DIR"

rsync -az --delete \
    -e "$SSH" \
    --exclude '.git' \
    --exclude '.env' \
    --exclude 'node_modules' \
    --exclude 'vendor' \
    --exclude 'public/build' \
    --exclude 'public/hot' \
    --exclude 'public/storage' \
    --exclude 'storage/app' \
    --exclude 'storage/logs' \
    --exclude 'storage/framework/cache' \
    --exclude 'storage/framework/sessions' \
    --exclude 'storage/framework/views' \
    --exclude '.phpunit.result.cache' \
    --exclude 'portal_check.html' \
    ./ "$HOST:$APP_DIR/"

echo "==> Releasing"
$SSH "$HOST" "cd $APP_DIR && bash deploy/release.sh"
