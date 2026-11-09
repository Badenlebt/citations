#!/usr/bin/env bash
# Stop everything the local dev stack started.
#
# Safe to run after you've already killed ./dev.sh (or if it crashed): it kills
# any leftover PHP API (:8000) and Vite (:5173) processes and stops the MariaDB
# container. Idempotent.
#
#   ./stop.sh           Stop servers + DB, keep the database data
#   ./stop.sh --wipe    Also delete the database volume (fresh schema+seed next run)

set -uo pipefail
cd "$(dirname "$0")"

COMPOSE="docker compose -f docker-compose.dev.yml"
WIPE=0
[ "${1:-}" = "--wipe" ] && WIPE=1

kill_port() {
  local port="$1" label="$2" pids
  pids=$(lsof -ti "tcp:${port}" 2>/dev/null || true)
  if [ -n "$pids" ]; then
    echo "Stopping ${label} (port ${port}, pid: ${pids//$'\n'/ })..."
    # shellcheck disable=SC2086
    kill $pids 2>/dev/null || true
    sleep 1
    pids=$(lsof -ti "tcp:${port}" 2>/dev/null || true)
    if [ -n "$pids" ]; then
      echo "  still alive, forcing..."
      # shellcheck disable=SC2086
      kill -9 $pids 2>/dev/null || true
    fi
  else
    echo "No ${label} running on port ${port}."
  fi
}

kill_port 8000 "PHP API"
kill_port 5173 "Vite dev server"

if [ "$WIPE" = "1" ]; then
  echo "Stopping MariaDB and WIPING its data volume..."
  $COMPOSE down -v
else
  echo "Stopping MariaDB (data kept)..."
  $COMPOSE down
fi

echo "Done. Remaining dev processes:"
lsof -i :8000 -i :5173 2>/dev/null || echo "  none on :8000 / :5173"
