#!/usr/bin/env bash
# Bootstrap a fresh Laravel app with cashier-orcarail (path repos) + e2e routes.
set -euo pipefail

APP_DIR="${APP_DIR:-/app}"
ORCARAIL_ROOT="${ORCARAIL_ROOT:-/orcarail}"
E2E_DIR="${E2E_DIR:-/e2e}"
MARKER="$APP_DIR/.orcarail-cashier-e2e-ready"

# Path mounts are owned by the host user; Composer/git need an exception.
git config --global --add safe.directory "$ORCARAIL_ROOT/sdks-php" || true
git config --global --add safe.directory "$ORCARAIL_ROOT/sdks-laravel-cashier" || true
git config --global --add safe.directory '*' || true

cd "$APP_DIR"

if [[ ! -f "$APP_DIR/artisan" ]]; then
  echo "Creating Laravel 12 project in $APP_DIR ..."
  # Pin to Laravel 12 — cashier supports illuminate ^10|^11|^12.
  composer create-project laravel/laravel:^12.0 . --no-interaction --prefer-dist
fi

# Path repositories for monorepo packages (always refresh).
composer config repositories.cashier path "$ORCARAIL_ROOT/sdks-laravel-cashier"
composer config repositories.orcarail-php path "$ORCARAIL_ROOT/sdks-php"
composer config minimum-stability dev
composer config prefer-stable true

if ! composer show orcarail/cashier-orcarail >/dev/null 2>&1; then
  echo "Requiring orcarail/cashier-orcarail from path..."
  composer require \
    orcarail/orcarail-php:dev-main \
    orcarail/cashier-orcarail:dev-main \
    --no-interaction \
    --with-all-dependencies
else
  # Ensure path packages stay linked after volume reuse.
  composer update orcarail/cashier-orcarail orcarail/orcarail-php --no-interaction || true
fi

# SQLite database
mkdir -p "$APP_DIR/database"
touch "$APP_DIR/database/database.sqlite"

# .env for e2e
if [[ ! -f "$APP_DIR/.env" ]]; then
  cp "$APP_DIR/.env.example" "$APP_DIR/.env"
  php artisan key:generate --force >/dev/null
fi

# Apply runtime config (idempotent via sed replacements / appends).
set_env() {
  local key="$1"
  local value="$2"
  if grep -q "^${key}=" "$APP_DIR/.env" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$APP_DIR/.env"
  else
    printf '\n%s=%s\n' "$key" "$value" >>"$APP_DIR/.env"
  fi
}

set_env "APP_ENV" "local"
set_env "APP_DEBUG" "true"
set_env "APP_URL" "${APP_URL:-http://127.0.0.1:8090}"
set_env "DB_CONNECTION" "sqlite"
set_env "DB_DATABASE" "$APP_DIR/database/database.sqlite"
# Clear mysql-style leftovers that break sqlite.
sed -i '/^DB_HOST=/d;/^DB_PORT=/d;/^DB_USERNAME=/d;/^DB_PASSWORD=/d' "$APP_DIR/.env" || true

set_env "ORCARAIL_API_KEY" "${ORCARAIL_API_KEY:-}"
set_env "ORCARAIL_API_SECRET" "${ORCARAIL_API_SECRET:-}"
set_env "ORCARAIL_WEBHOOK_SECRET" "${ORCARAIL_WEBHOOK_SECRET:-${ORCARAIL_API_SECRET:-}}"
set_env "ORCARAIL_BASE_URL" "${ORCARAIL_BASE_URL:-http://host.docker.internal:3000/api/v1}"
set_env "ORCARAIL_PAY_URL" "${ORCARAIL_PAY_URL:-http://host.docker.internal:5174}"
set_env "ORCARAIL_CURRENCY" "${ORCARAIL_CURRENCY:-usd}"
set_env "ORCARAIL_TOKEN_ID" "${ORCARAIL_TOKEN_ID:-}"
set_env "ORCARAIL_NETWORK_ID" "${ORCARAIL_NETWORK_ID:-}"
set_env "ORCARAIL_WEBHOOK_PATH" "orcarail/webhook"

# Publish + migrate Cashier
php artisan vendor:publish --provider="OrcaRail\\Cashier\\CashierServiceProvider" --tag=orcarail-cashier-config --force >/dev/null 2>&1 || true
php artisan migrate --force --no-interaction

# Patch User model with Billable
USER_MODEL="$APP_DIR/app/Models/User.php"
if [[ -f "$USER_MODEL" ]] && ! grep -q 'OrcaRail\\Cashier\\Billable' "$USER_MODEL"; then
  php -r '
    $path = $argv[1];
    $src = file_get_contents($path);
    if (str_contains($src, "OrcaRail\\Cashier\\Billable")) {
      exit(0);
    }
    $src = preg_replace(
      "/^namespace App\\\\Models;/m",
      "namespace App\\Models;\n\nuse OrcaRail\\Cashier\\Billable;",
      $src,
      1
    );
    if (!preg_match("/use Billable;/", $src)) {
      $src = preg_replace(
        "/(class User extends Authenticatable\s*\{)/",
        "$1\n    use Billable;\n",
        $src,
        1
      );
    }
    file_put_contents($path, $src);
  ' "$USER_MODEL"
fi

# Install e2e routes (no CSRF — loaded outside the web group via then:).
cp "$E2E_DIR/routes.php" "$APP_DIR/routes/e2e.php"
# Replace bootstrap with a known-good Laravel 12 template that loads e2e routes.
cp "$E2E_DIR/bootstrap-app.php" "$APP_DIR/bootstrap/app.php"

# Seed a billable user (id=1).
php artisan tinker --execute="
  \\App\\Models\\User::query()->firstOrCreate(
    ['email' => 'cashier-e2e@example.com'],
    ['name' => 'Cashier E2E', 'password' => bcrypt('password')]
  );
" >/dev/null 2>&1 || php -r '
  require "/app/vendor/autoload.php";
  $app = require "/app/bootstrap/app.php";
  $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
  $kernel->bootstrap();
  App\Models\User::query()->firstOrCreate(
    ["email" => "cashier-e2e@example.com"],
    ["name" => "Cashier E2E", "password" => bcrypt("password")]
  );
  echo "seeded\n";
'

touch "$MARKER"
echo "Laravel Cashier e2e app ready."
