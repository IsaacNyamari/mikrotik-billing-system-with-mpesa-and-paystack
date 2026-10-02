#!/usr/bin/env bash
#
# What is actually running on the server?
#
#   ./deploy/verify.sh
#
# This used to hash every file on both sides, because the server ran a tarball of somebody's
# working tree and a comparison was the only evidence of anything. Deploys come from git now,
# so the answer is a commit — and the useful questions become whether that commit is one you
# have, whether anything has been edited on the server since, and whether the services that
# are supposed to be running are.
#
# Exits non-zero if the server is not on a commit this repository knows, or has been edited.

set -euo pipefail

HOST="${DEPLOY_HOST:-root@solforbs.com}"
KEY="${DEPLOY_KEY:-$HOME/.ssh/solforbs_deploy}"
APP_DIR="${DEPLOY_PATH:-/var/www/isp}"
REMOTE="${REMOTE:-origin}"

SSH=(ssh -i "$KEY" -o BatchMode=yes)

cd "$(dirname "$0")/.."

echo "==> Asking ${HOST}"

remote_state="$(
    "${SSH[@]}" "$HOST" "
        cd ${APP_DIR} 2>/dev/null || { echo 'NOCHECKOUT'; exit 0; }
        [ -d .git ] || { echo 'NOCHECKOUT'; exit 0; }
        echo \"COMMIT \$(git rev-parse HEAD)\"
        if git diff --quiet && git diff --cached --quiet; then
            echo 'CLEAN'
        else
            echo 'DIRTY'
            git --no-pager diff --stat HEAD | sed 's/^/  /'
        fi
        for unit in nginx php8.3-fpm isp-scheduler.service isp-queue.service; do
            echo \"UNIT \$unit \$(systemctl is-active \$unit 2>/dev/null || echo unknown)\"
        done
    "
)"

if grep -q '^NOCHECKOUT$' <<<"$remote_state"; then
    echo "  ${APP_DIR} is not a git checkout. Run deploy/provision.sh on the server." >&2
    exit 1
fi

commit="$(grep '^COMMIT ' <<<"$remote_state" | awk '{print $2}')"
status=0

echo
echo "Running: ${commit:0:7}"

git fetch --quiet --tags "$REMOTE" 2>/dev/null || true

if git cat-file -e "${commit}^{commit}" 2>/dev/null; then
    git --no-pager log -1 --pretty='  %s%n  %an, %ad' --date=short "$commit"

    # Behind is normal and worth knowing; it is the difference between "deployed" and "current".
    behind="$(git rev-list --count "${commit}..${REMOTE}/main" 2>/dev/null || echo '?')"
    if [ "$behind" != "0" ] && [ "$behind" != "?" ]; then
        echo "  ${behind} commit(s) behind ${REMOTE}/main"
    else
        echo "  up to date with ${REMOTE}/main"
    fi
else
    # A commit nobody here has is a commit nobody can read. Either it was force-pushed away or
    # somebody committed on the server, and both are worth stopping for.
    echo "  UNKNOWN COMMIT — not in this repository." >&2
    echo "  Fetch, or find out who deployed it from where." >&2
    status=1
fi

echo
if grep -q '^DIRTY$' <<<"$remote_state"; then
    echo "Working tree: EDITED ON THE SERVER" >&2
    grep -A100 '^DIRTY$' <<<"$remote_state" | tail -n +2 >&2
    echo "  The next release will refuse until this is committed, stashed or discarded." >&2
    status=1
else
    echo "Working tree: clean"
fi

echo
echo "Services:"
grep '^UNIT ' <<<"$remote_state" | while read -r _ unit state; do
    printf '  %-26s %s\n' "$unit" "$state"
    [ "$state" = "active" ] || status=1
done

# The loop above runs in a subshell, so its status cannot escape; re-check here.
if grep '^UNIT ' <<<"$remote_state" | grep -qv ' active$'; then
    echo "  Something that should be running is not." >&2
    status=1
fi

exit "$status"
