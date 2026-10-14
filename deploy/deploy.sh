#!/usr/bin/env bash
# Zero-surprise update script for an existing installation.
# Usage (as the deploy user, from the project directory):  bash deploy/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

echo "→ Maintenance mode"
php artisan down --retry=15 || true

echo "→ Pulling code"
git pull --ff-only

echo "→ PHP dependencies"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

echo "→ Frontend assets"
npm ci --no-audit --no-fund
npm run build

echo "→ Database"
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=PlansSeeder --force

echo "→ Caches"
php artisan optimize:clear
php artisan optimize          # config, routes, views, events

echo "→ Restart queue workers (they finish their current job first)"
php artisan queue:restart

echo "→ Recover anything interrupted during the deploy"
php artisan campaigns:recover

php artisan up
echo "✓ Deployed"
