#!/bin/sh
# Mountaineer IT - every check in one go: after an upstream merge, before "Promote to production", or any time.
#   sh scripts/mit_verify.sh [install folder]      default /var/www/itflow (the test install - never production for the smoke tests)
#   MIT_VERIFY_SKIP_SMOKE=1 sh scripts/mit_verify.sh /var/www/itflow    the read-only checks only (safe on production)
# Runs: PHP syntax of every file but libs/, the hook check (our hooks in ITFlow's files), the consistency check and the stock
# ITFlow portability check on the install, then the smoke tests. Stops at the first failure.
set -e
REPO="$(cd "$(dirname "$0")/.." && pwd)"
INSTALL="${1:-/var/www/itflow}"

echo "== PHP syntax"
find "$REPO" -name '*.php' -not -path '*/libs/*' -not -path '*/vendor/*' -not -path '*/node_modules/*' -print0 \
    | xargs -0 -n 50 -P 4 php -l 2>&1 | grep -v '^No syntax errors' | grep . && { echo "PHP syntax errors above"; exit 1; } || true

echo "== Hook check"
php "$REPO/scripts/mit_hook_check.php"

echo "== Consistency check ($INSTALL)"
php "$INSTALL/scripts/mit_consistency_check.php"

echo "== Portability check ($INSTALL)"
PORT="$(php "$INSTALL/scripts/mit_portability_check.php")" || { echo "$PORT"; exit 1; }
echo "$PORT" | grep -E '^Portable'

if [ -z "$MIT_VERIFY_SKIP_SMOKE" ]; then
    echo "== Smoke tests"
    cd "$REPO/tests/smoke" && ITFLOW_PHP_LOG="${ITFLOW_PHP_LOG:-/var/log/apache2/itflow-error.log}" ITFLOW_ROOT="$INSTALL" npx playwright test
fi
echo "== All checks passed"
