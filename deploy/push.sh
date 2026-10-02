#!/usr/bin/env bash
#
# Deploy, from the development machine:
#
#   ./deploy/push.sh                # deploy origin/main
#   REF=v1.4.0 ./deploy/push.sh     # deploy a tag, a branch or a commit
#
# This used to tar the working tree and send it over SSH. It no longer does, and the reason is
# worth keeping: what ran on the server could not be traced back to anything. It was whatever
# happened to be on one laptop at one moment, including work nobody had committed, and the only
# way to answer "what is live" was to hash every file on both sides and compare.
#
# Now the server pulls a commit from GitHub. "What is live" is a short hash, and `git log` is
# the rest of the answer.
#
# So this script's job is only to check that what you are asking for has actually been pushed,
# and then ask the server to go and get it.

set -euo pipefail

HOST="${DEPLOY_HOST:-root@solforbs.com}"
KEY="${DEPLOY_KEY:-$HOME/.ssh/solforbs_deploy}"
APP_DIR="${DEPLOY_PATH:-/var/www/isp}"
REMOTE="${REMOTE:-origin}"
REF="${REF:-main}"

# An array rather than a string: the key path routinely contains a space on Windows
# ("C:\Users\Pro Codes"), and a plain "$SSH" would split it into two arguments.
SSH=(ssh -i "$KEY" -o BatchMode=yes)

cd "$(dirname "$0")/.."

echo "==> Checking ${REF} is pushed"

# Deploying a commit that exists only here is the one failure this script can prevent, and it
# is the one people hit: commit, deploy, and wonder why nothing changed.
if ! git rev-parse --verify --quiet "$REF" >/dev/null && ! git rev-parse --verify --quiet "${REMOTE}/${REF}" >/dev/null; then
    echo "  ${REF} is not a branch, tag or commit here." >&2
    exit 1
fi

git fetch --quiet --tags "$REMOTE"

local_ref="$(git rev-parse "$REF" 2>/dev/null || git rev-parse "${REMOTE}/${REF}")"

if ! git branch -r --contains "$local_ref" 2>/dev/null | grep -q .; then
    echo "  ${REF} (${local_ref:0:7}) has not been pushed to ${REMOTE}." >&2
    echo "  The server pulls from GitHub, so it cannot see it. Push first:" >&2
    echo >&2
    echo "      git push ${REMOTE} HEAD" >&2
    exit 1
fi

echo "  ${REF} is ${local_ref:0:7}, present on ${REMOTE}"

# Said plainly rather than hidden, because it is the difference between deploying what you are
# looking at and deploying what you last pushed.
if ! git diff --quiet HEAD 2>/dev/null; then
    echo
    echo "  Note: you have uncommitted changes. They are NOT part of this deploy."
    git --no-pager diff --stat HEAD | sed 's/^/    /'
    echo
fi

echo "==> Releasing on ${HOST}"

"${SSH[@]}" "$HOST" "REF='${REF}' bash ${APP_DIR}/deploy/release.sh"
