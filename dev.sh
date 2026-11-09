#!/usr/bin/env bash
# Local dev launcher for the citations site.
#
# Brings up the whole stack:
#   1. MariaDB (docker compose, auto-loads db/schema.sql + db/seed.sql)
#   2. PHP REST API front controller on http://localhost:8000  (serves api/)
#   3. Vite dev server on http://localhost:5173 (proxies /api -> :8000)
#
# Open http://localhost:5173 in your browser. Admin login: admin / admin.
# Press Ctrl-C to stop the PHP + Vite servers. The database keeps running in the
# background; stop it with:  docker compose -f docker-compose.dev.yml down
#
# Requirements: docker, php, node/npm, and api/vendor (composer install).

set -euo pipefail
cd "$(dirname "$0")"

COMPOSE="docker compose -f docker-compose.dev.yml"

cleanup() {
  echo ""
  echo "Stopping PHP and Vite..."
  [ -n "${PHP_PID:-}" ] && kill "$PHP_PID" 2>/dev/null || true
  [ -n "${VITE_PID:-}" ] && kill "$VITE_PID" 2>/dev/null || true
  echo "Database still running. Stop it with: $COMPOSE down"
}
trap cleanup EXIT INT TERM

if [ ! -f config.php ]; then
  echo "ERROR: config.php is missing at the repo root. See DEVELOPMENT.md." >&2
  exit 1
fi

if [ ! -f api/vendor/autoload.php ]; then
  echo "Installing PHP dependencies (composer install)..."
  (cd api && composer install --no-interaction)
fi

if [ ! -d frontend/node_modules ]; then
  echo "Installing frontend dependencies (npm install)..."
  (cd frontend && npm install)
fi

echo "Starting MariaDB..."
$COMPOSE up -d

echo "Waiting for MariaDB to become healthy..."
for i in $(seq 1 60); do
  status=$($COMPOSE ps --format '{{.Health}}' db 2>/dev/null || echo "")
  if [ "$status" = "healthy" ]; then
    break
  fi
  sleep 2
  if [ "$i" = "60" ]; then
    echo "ERROR: MariaDB did not become healthy in time." >&2
    exit 1
  fi
done
echo "MariaDB is ready."

echo "Starting PHP API on http://localhost:8000 ..."
php -S localhost:8000 api/index.php &
PHP_PID=$!

echo "Starting Vite dev server ..."
(cd frontend && npm run dev) &
VITE_PID=$!

echo ""
echo "======================================================================"
echo " Site:        http://localhost:5173"
echo " API direct:  http://localhost:8000/api/random"
echo " Admin login: admin / admin"
echo " Press Ctrl-C to stop (database keeps running)."
echo "======================================================================"

wait
