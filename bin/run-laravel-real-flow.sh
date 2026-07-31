#!/usr/bin/env bash
# Orchestrate real-flow backend+pay + Laravel Docker + Cashier smoke (local webhooks).
set -euo pipefail

PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ORCARAIL_ROOT="${ORCARAIL_ROOT:-$(cd "$PKG_DIR/.." && pwd)}"
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
fi

LARAVEL_PORT="${LARAVEL_PORT:-8090}"
LARAVEL_URL="${LARAVEL_URL:-http://127.0.0.1:${LARAVEL_PORT}}"
API_HOST="${ORCARAIL_API_HOST:-http://127.0.0.1:3000}"
PAY_ORIGIN="${ORCARAIL_PAY_ORIGIN:-http://127.0.0.1:5174}"
WEBHOOK_URL="${ORCARAIL_WEBHOOK_URL:-${LARAVEL_URL}/orcarail/webhook}"
KEEP_LARAVEL="${ORCARAIL_KEEP_LARAVEL:-0}"
LOG_DIR="${ORCARAIL_LARAVEL_LOG_DIR:-$PKG_DIR/.real-flow-logs}"
mkdir -p "$LOG_DIR"

BACKEND_PID=""
PAY_PID=""

cleanup() {
  local code=$?
  if [[ -n "$BACKEND_PID" ]] && kill -0 "$BACKEND_PID" 2>/dev/null; then
    kill -- "-$BACKEND_PID" 2>/dev/null || kill "$BACKEND_PID" 2>/dev/null || true
  fi
  if [[ -n "$PAY_PID" ]] && kill -0 "$PAY_PID" 2>/dev/null; then
    kill -- "-$PAY_PID" 2>/dev/null || kill "$PAY_PID" 2>/dev/null || true
  fi
  if [[ "$KEEP_LARAVEL" != "1" ]]; then
    "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
  fi
  exit "$code"
}
trap cleanup EXIT

wait_http() {
  local url="$1"
  local label="$2"
  local max="${3:-120}"
  local i
  for i in $(seq 1 "$max"); do
    if curl -s -o /dev/null "$url" 2>/dev/null; then
      echo "OK $label ($url)"
      return 0
    fi
    sleep 1
  done
  echo "Timed out waiting for $label at $url" >&2
  return 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Required command not found: $1" >&2
    exit 1
  }
}

need_cmd docker
need_cmd curl
need_cmd jq
need_cmd openssl

# shellcheck source=/dev/null
source "$ORCARAIL_ROOT/scripts/load-real-flow-env.sh"

export API_KEY_WEBHOOK_SECRET_ENCRYPTION_KEY="${API_KEY_WEBHOOK_SECRET_ENCRYPTION_KEY:-orcarail-laravel-real-flow-dev-key}"
export ORCARAIL_SEED_WEBHOOK_URL="$WEBHOOK_URL"
export PAY_URL="${PAY_URL:-$PAY_ORIGIN}"
export REAL_FLOW_TEST_MODE=1

echo "Ensuring Redis + MailDev for real-flow..."
(
  cd "$ORCARAIL_ROOT/api"
  docker compose up -d redis maildev
)

if curl -s -o /dev/null "$API_HOST/api/v1/organizations" 2>/dev/null; then
  echo "Reusing existing API at $API_HOST"
else
  echo "Starting real-flow combined backend..."
  set -m
  ORCARAIL_NO_RTK=1 "$ORCARAIL_ROOT/scripts/start-real-flow-backend.sh" \
    >"$LOG_DIR/backend.log" 2>&1 &
  BACKEND_PID=$!
  set +m
  wait_http "$API_HOST/api/v1/organizations" "api" 180 \
    || {
      tail -n 80 "$LOG_DIR/backend.log" >&2 || true
      exit 1
    }
fi

if curl -s -o /dev/null "$PAY_ORIGIN/" 2>/dev/null; then
  echo "Reusing existing pay at $PAY_ORIGIN"
else
  echo "Starting pay real-flow..."
  set -m
  ORCARAIL_NO_RTK=1 "$ORCARAIL_ROOT/scripts/start-pay-real-flow.sh" \
    >"$LOG_DIR/pay.log" 2>&1 &
  PAY_PID=$!
  set +m
  wait_http "$PAY_ORIGIN/" "pay" 120 \
    || {
      tail -n 80 "$LOG_DIR/pay.log" >&2 || true
      exit 1
    }
fi

DEMO_ENV="$ORCARAIL_ROOT/demo/.env.local"
for i in $(seq 1 60); do
  if [[ -f "$DEMO_ENV" ]] && grep -q '^ORCARAIL_API_KEY=' "$DEMO_ENV" 2>/dev/null; then
    break
  fi
  if ((i == 60)); then
    echo "Timed out waiting for $DEMO_ENV credentials" >&2
    exit 1
  fi
  sleep 1
done

# shellcheck disable=SC1090
set -a
source "$DEMO_ENV"
set +a

if [[ -z "${ORCARAIL_API_KEY:-}" || -z "${ORCARAIL_API_SECRET:-}" ]]; then
  echo "ORCARAIL_API_KEY / ORCARAIL_API_SECRET missing in $DEMO_ENV" >&2
  exit 1
fi

echo "Configuring demo API key webhook → $WEBHOOK_URL"
CONFIG_JSON="$(
  curl -fsS -X POST "$API_HOST/api/__real-flow/configure-woo" \
    -H 'Content-Type: application/json' \
    -d "$(jq -n --arg u "$WEBHOOK_URL" '{webhookUrl:$u}')"
)"
echo "$CONFIG_JSON" | jq .

TOKEN_ID="$(echo "$CONFIG_JSON" | jq -r '.tokenId')"
NETWORK_ID="$(echo "$CONFIG_JSON" | jq -r '.networkId')"
if [[ -z "$TOKEN_ID" || "$TOKEN_ID" == null || -z "$NETWORK_ID" || "$NETWORK_ID" == null ]]; then
  echo "configure-woo failed: $CONFIG_JSON" >&2
  exit 1
fi

# Webhook HMAC secret matches real-flow signing (same as Woo harness).
ORCARAIL_WEBHOOK_SECRET="${ORCARAIL_WEBHOOK_SECRET:-$ORCARAIL_API_SECRET}"

{
  echo "LARAVEL_PORT=${LARAVEL_PORT}"
  echo "LARAVEL_URL=${LARAVEL_URL}"
  echo "ORCARAIL_API_KEY=${ORCARAIL_API_KEY}"
  echo "ORCARAIL_API_SECRET=${ORCARAIL_API_SECRET}"
  echo "ORCARAIL_WEBHOOK_SECRET=${ORCARAIL_WEBHOOK_SECRET}"
  echo "ORCARAIL_TOKEN_ID=${TOKEN_ID}"
  echo "ORCARAIL_NETWORK_ID=${NETWORK_ID}"
  echo "ORCARAIL_BASE_URL=http://host.docker.internal:3000/api/v1"
  echo "ORCARAIL_PAY_URL=http://host.docker.internal:5174"
  echo "ORCARAIL_CURRENCY=usd"
} >"$PKG_DIR/.env.e2e"

export ORCARAIL_TOKEN_ID="$TOKEN_ID"
export ORCARAIL_NETWORK_ID="$NETWORK_ID"
export ORCARAIL_WEBHOOK_SECRET

# Recreate compose with generated env so the container sees credentials.
COMPOSE=(docker compose -f docker-compose.e2e.yml -p orcarail-laravel --env-file "$PKG_DIR/.env.e2e")

bash "$PKG_DIR/bin/laravel-up.sh"

echo "Creating subscription via /e2e/subscribe ..."
SUB_JSON="$(
  curl -fsS -X POST "$LARAVEL_URL/e2e/subscribe" \
    -H 'Content-Type: application/json' \
    -d "$(jq -n \
      --arg t "$TOKEN_ID" \
      --arg n "$NETWORK_ID" \
      '{token_id:$t, network_id:$n, amount:"10.00", currency:"usd", interval:"month"}')"
)"
echo "$SUB_JSON" | jq .

ORCARAIL_SUB_ID="$(echo "$SUB_JSON" | jq -r '.orcarail_id')"
PAY_SLUG="$(echo "$SUB_JSON" | jq -r '.pay_slug')"
PAY_URL_RESULT="$(echo "$SUB_JSON" | jq -r '.pay_url')"

if [[ -z "$ORCARAIL_SUB_ID" || "$ORCARAIL_SUB_ID" == null ]]; then
  echo "subscribe failed: missing orcarail_id" >&2
  exit 1
fi
if [[ -z "$PAY_SLUG" || "$PAY_SLUG" == null ]]; then
  echo "subscribe failed: missing pay_slug (latest_payment_link)" >&2
  exit 1
fi

case "$PAY_URL_RESULT" in
  http://127.0.0.1:5174*|http://localhost:5174*|http://host.docker.internal:5174*) ;;
  *)
    echo "pay_url is not the local pay origin: $PAY_URL_RESULT" >&2
    exit 1
    ;;
esac

STATE0="$(curl -fsS "$LARAVEL_URL/e2e/state")"
echo "$STATE0" | jq .
if ! echo "$STATE0" | jq -e --arg id "$ORCARAIL_SUB_ID" \
  '.subscriptions | map(select(.orcarail_id == $id)) | length > 0' >/dev/null; then
  echo "Local subscription row missing after create" >&2
  exit 1
fi

echo "Simulating payment complete for subscription slug=$PAY_SLUG ..."
SIM_JSON="$(
  curl -fsS -X POST "$API_HOST/api/__real-flow/simulate" \
    -H 'Content-Type: application/json' \
    -d "$(jq -n --arg s "$PAY_SLUG" '{action:"complete-first-payment",FLOW_PAYMENT_LINK_SLUG:$s}')"
)"
echo "$SIM_JSON" | jq .

echo "Polling __real-flow/state for webhook delivery to Laravel..."
DELIVERED=0
for i in $(seq 1 30); do
  STATE_JSON="$(
    curl -fsS -X POST "$API_HOST/api/__real-flow/state" \
      -H 'Content-Type: application/json' \
      -d '{"organizationSlug":"demo","limit":20}'
  )"
  if echo "$STATE_JSON" | jq -e \
    --arg port "$LARAVEL_PORT" \
    '.webhooks // [] | map(select((.url // "") | test("orcarail/webhook|" + $port))) | length > 0' \
    >/dev/null 2>&1; then
    DELIVERED=1
    echo "webhook_log matched Laravel URL (attempt $i)"
    break
  fi
  sleep 1
done

# Signed fallback keeps the harness deterministic (same as Woo).
PAYLOAD="$(
  jq -n \
    --arg id "$ORCARAIL_SUB_ID" \
    '{
      type: "subscription.updated",
      data: {
        object: {
          id: $id,
          status: "active",
          trial_end: null,
          ended_at: null,
          canceled_at: null
        }
      },
      created: (now | floor)
    }'
)"
SECRET="${ORCARAIL_API_SECRET:?missing ORCARAIL_API_SECRET}"
SIGNATURE="$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')"

WH_CODE="$(
  curl -sS -o "$LOG_DIR/webhook-response.json" -w '%{http_code}' \
    -X POST "$WEBHOOK_URL" \
    -H 'Content-Type: application/json' \
    -H "X-Webhook-Signature: $SIGNATURE" \
    -H 'X-Webhook-Event: subscription.updated' \
    --data-binary "$PAYLOAD"
)"
echo "Signed webhook HTTP $WH_CODE: $(cat "$LOG_DIR/webhook-response.json")"
if [[ "$WH_CODE" != "200" ]]; then
  echo "Signed webhook to Laravel failed" >&2
  exit 1
fi

STATE1="$(curl -fsS "$LARAVEL_URL/e2e/state")"
echo "$STATE1" | jq .
if ! echo "$STATE1" | jq -e --arg id "$ORCARAIL_SUB_ID" \
  '.subscriptions | map(select(.orcarail_id == $id and .orcarail_status == "active")) | length > 0' >/dev/null; then
  echo "Subscription not active in local state after webhook" >&2
  exit 1
fi
if ! echo "$STATE1" | jq -e '.subscribed == true' >/dev/null; then
  echo "user.subscribed() expected true" >&2
  exit 1
fi

echo "Creating one-off checkout via /e2e/checkout ..."
CHECKOUT_JSON="$(
  curl -fsS -X POST "$LARAVEL_URL/e2e/checkout" \
    -H 'Content-Type: application/json' \
    -d "$(jq -n \
      --arg t "$TOKEN_ID" \
      --arg n "$NETWORK_ID" \
      '{tokenId:$t, networkId:$n, amount:"10.00", currency:"usd"}')"
)"
echo "$CHECKOUT_JSON" | jq .

INTENT_ID="$(echo "$CHECKOUT_JSON" | jq -r '.intent_id')"
CHECKOUT_SLUG="$(echo "$CHECKOUT_JSON" | jq -r '.pay_slug')"
CHECKOUT_URL="$(echo "$CHECKOUT_JSON" | jq -r '.pay_url')"

if [[ -z "$INTENT_ID" || "$INTENT_ID" == null ]]; then
  echo "checkout failed: missing intent_id" >&2
  exit 1
fi
if [[ -z "$CHECKOUT_SLUG" || "$CHECKOUT_SLUG" == null ]]; then
  echo "checkout failed: missing pay_slug" >&2
  exit 1
fi

case "$CHECKOUT_URL" in
  http://127.0.0.1:5174*|http://localhost:5174*|http://host.docker.internal:5174*) ;;
  *)
    echo "checkout pay_url is not the local pay origin: $CHECKOUT_URL" >&2
    exit 1
    ;;
esac

echo "Simulating payment complete for checkout slug=$CHECKOUT_SLUG ..."
SIM2_JSON="$(
  curl -fsS -X POST "$API_HOST/api/__real-flow/simulate" \
    -H 'Content-Type: application/json' \
    -d "$(jq -n --arg s "$CHECKOUT_SLUG" '{action:"complete-first-payment",FLOW_PAYMENT_LINK_SLUG:$s}')"
)"
echo "$SIM2_JSON" | jq .

echo ""
echo "Laravel Cashier real-flow smoke passed."
echo "  subscription=$ORCARAIL_SUB_ID"
echo "  intent=$INTENT_ID"
echo "  webhook_url=$WEBHOOK_URL"
if [[ "$DELIVERED" == "1" ]]; then
  echo "  async webhook_log: observed"
else
  echo "  async webhook_log: not observed (signed fallback used)"
fi
if [[ "$KEEP_LARAVEL" == "1" ]]; then
  echo "  KEEP_LARAVEL=1 — leaving Laravel stack up at $LARAVEL_URL"
fi
