#!/usr/bin/env bash
# Starts a throwaway copy of the Docker setup, creates an admin and the two
# terminals, runs smoke_test.py against it, then removes everything.
# Used by .github/workflows/smoke-test.yml; also works locally (Linux, macOS,
# Git Bash) from the repository root:  bash tests/ci.sh
#
# Fails when a functional test fails OR a security check reports OPEN.

set -euo pipefail
export MSYS_NO_PATHCONV=1

PROJECT="${COMPOSE_PROJECT:-unismart-ci}"
export APP_PORT="${APP_PORT:-8090}"
export APP_ROOT="http://localhost:${APP_PORT}/UniSmartPay"
export ADMIN_EMAIL="ci-admin@example.com"
export ADMIN_PASS="Ci-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"

dc() { docker compose -p "$PROJECT" "$@"; }

cleanup() {
  status=$?
  if [ "$status" -ne 0 ]; then
    echo "::group::docker compose logs"
    dc logs --no-color --tail 200 || true
    echo "::endgroup::"
  fi
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  exit "$status"
}
trap cleanup EXIT

echo "== Starting containers"
dc up -d --build --wait

# The db healthcheck can pass while MySQL is still loading the schema (it
# runs a temporary server without networking first). Wait until the seed
# data is reachable over TCP, the way the app connects.
echo "== Waiting for the database schema"
for i in $(seq 1 60); do
  if dc exec -T db sh -c 'mysql -h 127.0.0.1 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -N -e "SELECT COUNT(*) FROM cartes"' >/dev/null 2>&1; then
    break
  fi
  [ "$i" -eq 60 ] && { echo "Database not ready after 120s"; exit 1; }
  sleep 2
done

echo "== Creating admin and terminals"
printf '%s\n%s\n' "$ADMIN_PASS" "$ADMIN_PASS" \
  | dc exec -T app php /opt/unismartpay/scripts/create_admin.php "$ADMIN_EMAIL" CI Admin super_admin 2>/dev/null

new_terminal() {  # prints "<id> <token>"
  local out
  out=$(dc exec -T app php /opt/unismartpay/scripts/create_terminal.php "$1" "$2" 1)
  echo "$(echo "$out" | sed -n 's/.*(id \([0-9]*\)).*/\1/p') $(echo "$out" | tail -n 1)"
}
read -r RESTO_TERMINAL RESTO_TOKEN < <(new_terminal "CI Resto" RESTO)
read -r BUVETTE_TERMINAL BUVETTE_TOKEN < <(new_terminal "CI Buvette" BUVETTE)
export RESTO_TERMINAL RESTO_TOKEN BUVETTE_TERMINAL BUVETTE_TOKEN
echo "RESTO terminal $RESTO_TERMINAL, BUVETTE terminal $BUVETTE_TERMINAL"

echo "== Running smoke test"
OUTPUT=$(mktemp)
python3 tests/smoke_test.py --lockout | tee "$OUTPUT"
# smoke_test.py already exits 1 on a functional failure (pipefail keeps it).
if ! grep -Eq 'Security: [0-9]+ fixed, 0 open' "$OUTPUT"; then
  echo "A security check reported OPEN (regression)."
  exit 1
fi
