#!/usr/bin/env bash
# The DEPLOY GATE. deploy.sh calls it right after "git pull", BEFORE anything else (migrations, caches ...).
# It runs the guard on the version that was just downloaded. If something we added is missing or a PHP file is broken,
# it puts the server BACK on the previous version, so visitors never get the broken one, and it exits with 1
# (the deploy in GitHub turns red and tells you why).
#   usage in deploy.sh:   OLD=$(git rev-parse HEAD)      <- BEFORE the git pull
#                         git pull origin main
#                         bash tools/deploy_gate.sh "$OLD" || exit 1
OLD="$1"
[ -n "$OLD" ] || { echo "deploy_gate: the previous version was not given"; exit 2; }
cd "$(dirname "$0")/.." || exit 2
NEW=$(git rev-parse --short HEAD)
if [ ! -f tools/check_required.sh ]; then
  echo "DEPLOY REFUSED: tools/check_required.sh is missing in version $NEW. Going back to $OLD."
  git reset --hard "$OLD" >/dev/null 2>&1; exit 1
fi
if bash tools/check_required.sh; then
  echo "deploy_gate: version $NEW passed the guard."; exit 0
fi
echo
echo "DEPLOY REFUSED: version $NEW lost something we added (see above). The server goes BACK to $OLD and stays working."
echo "Fix it on GitHub, then push again."
git reset --hard "$OLD" >/dev/null 2>&1
exit 1
