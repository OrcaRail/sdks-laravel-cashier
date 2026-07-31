#!/usr/bin/env bash
# Container entrypoint: ensure Laravel app exists, then serve.
set -euo pipefail

bash /e2e/app-setup.sh
exec php artisan serve --host=0.0.0.0 --port=8000
