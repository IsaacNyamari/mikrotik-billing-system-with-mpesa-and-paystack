#!/usr/bin/env bash
#
# Prove that the source running on the server is the source on this machine.
#
#   ./deploy/verify.sh
#
# There is no git remote, so nothing else can answer this question. What is on this machine is
# what runs, and the only evidence of that is a comparison — which is worth having because
# push.sh sends a tar, and tar overwrites and adds but never deletes. A file removed here stays
# on the server forever unless somebody looks.
#
# Exits non-zero when they differ, so it can gate anything that cares.

set -euo pipefail

HOST="${DEPLOY_HOST:-root@solforbs.com}"
KEY="${DEPLOY_KEY:-$HOME/.ssh/solforbs_deploy}"
APP_DIR="${DEPLOY_PATH:-/var/www/isp}"

SSH=(ssh -i "$KEY" -o BatchMode=yes)

cd "$(dirname "$0")/.."

if [ ! -f deploy/excludes.txt ]; then
    echo "deploy/excludes.txt is missing — cannot tell source from scratch space." >&2
    exit 1
fi

# The manifest generator, built once and run on both sides so the two answers are comparable.
#
# The exclusions come from the same file push.sh sends with, so this can never quietly compare
# something that was never transferred and report it as drift.
build_manifest_script() {
    printf '%s\n' 'find . -type f \'

    # Comments and blank lines out; each remaining path becomes a prune for the entry itself
    # and for everything beneath it.
    grep -v '^[[:space:]]*#' deploy/excludes.txt | grep -v '^[[:space:]]*$' | while read -r path; do
        printf '  -not -path "%s" -not -path "%s/*" \\\n' "$path" "$path"
    done

    # sha1sum writes "hash  ./path" with GNU coreutils and "hash *./path" in Git Bash, where
    # the star means it read the file in binary mode. Both are flattened to "hash path" here,
    # or the two sides agree on every byte and still report every file as different.
    #
    # sed rather than awk: the escaping survives being passed through ssh, and mawk and the awk
    # Git Bash ships do not treat the same substitution identically — which is exactly the sort
    # of difference that makes a comparison tool lie.
    printf '%s\n' '  -print0 | LC_ALL=C sort -z | xargs -0 sha1sum 2>/dev/null |'
    printf '%s\n' '  sed -e "s|^\([0-9a-f]*\)[ ]*[*]\{0,1\}\./|\1 |"'
}

SCRIPT="$(build_manifest_script)"

echo "==> Hashing the source here"
LOCAL="$(mktemp)"
printf '%s' "$SCRIPT" | bash | LC_ALL=C sort -k2 > "$LOCAL"

echo "==> Hashing the source on $HOST"
REMOTE="$(mktemp)"
printf '%s' "$SCRIPT" | "${SSH[@]}" "$HOST" "cd $APP_DIR && bash -s" | LC_ALL=C sort -k2 > "$REMOTE"

trap 'rm -f "$LOCAL" "$REMOTE"' EXIT

only_here="$(comm -23 <(awk '{print $2}' "$LOCAL") <(awk '{print $2}' "$REMOTE"))"
only_there="$(comm -13 <(awk '{print $2}' "$LOCAL") <(awk '{print $2}' "$REMOTE"))"
changed="$(join -j 2 -o 0,1.1,2.1 "$LOCAL" "$REMOTE" | awk '$2 != $3 { print $1 }')"

status=0

if [ -n "$only_here" ]; then
    echo
    echo "Never reached the server — deploy again:"
    printf '  %s\n' $only_here
    status=1
fi

if [ -n "$only_there" ]; then
    echo
    # This is the one push.sh cannot fix by itself. tar unpacks over the top of what is there;
    # it has no idea a file used to exist and no longer should.
    echo "Left behind on the server after being deleted here:"
    printf '  %s\n' $only_there
    echo "  Remove them on the server, or they stay forever."
    status=1
fi

if [ -n "$changed" ]; then
    echo
    echo "Different contents:"
    printf '  %s\n' $changed
    status=1
fi

echo
if [ "$status" -eq 0 ]; then
    echo "Identical: $(wc -l < "$LOCAL" | tr -d ' ') files match on both sides."
else
    echo "The server is not running what is on this machine."
fi

exit "$status"
