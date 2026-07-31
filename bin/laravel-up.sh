#!/usr/bin/env bash
# Bring up the Laravel Cashier e2e container and wait until /e2e/state responds.
set -euo pipefail

PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PKG_DIR"

COMPOSE=(docker compose -f docker-compose.e2e.yml -p orcarail-laravel)
ENV_FILE="${ORCARAIL_LARAVEL_ENV_FILE:-$PKG_DIR/.env.e2e}"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
  COMPOSE+=(--env-file "$ENV_FILE")
elif [[ -f "$PKG_DIR/.env.e2e.example" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$PKG_DIR/.env.e2e.example"
  set +a
  COMPOSE+=(--env-file "$PKG_DIR/.env.e2e.example")
fi

LARAVEL_URL="${LARAVEL_URL:-http://127.0.0.1:${LARAVEL_PORT:-8090}}"

echo "Building / starting Laravel Cashier e2e stack (project orcarail-laravel)..."
"${COMPOSE[@]}" up -d --build app

echo "Waiting for Laravel e2e at $LARAVEL_URL/e2e/state ..."
for i in $(seq 1 120); do
  if curl -fsS -o /dev/null "$LARAVEL_URL/e2e/state" 2>/dev/null; then
    echo "Laravel ready at $LARAVEL_URL"
    curl -fsS "$LARAVEL_URL/e2e/state" | head -c 500 || true
    echo ""
    exit 0
  fi
  if ((i % 15 == 0)); then
    echo "  still waiting ($i/120)..."
    "${COMPOSE[@]}" logs --tail=40 app >&2 || true
  fi
  sleep 2
done

echo "Timed out waiting for $LARAVEL_URL/e2e/state" >&2
"${COMPOSE[@]}" logs --tail=120 app >&2 || true
exit 1
