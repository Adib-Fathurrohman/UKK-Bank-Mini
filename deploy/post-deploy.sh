#!/usr/bin/env bash
# Runs on the VPS after each upload (called by .github/workflows/ci-cd.yml).
# Optional environment: WEB_USER (default www-data), PHP_FPM_SERVICE (e.g. php8.4-fpm).
set -euo pipefail

cd "$(dirname "$0")/.."
WEB_USER="${WEB_USER:-www-data}"

if [ ! -f .env ]; then
    echo "Missing $(pwd)/.env. Create it once on the server (see docs/DEPLOY.md), then re-run the deploy." >&2
    exit 1
fi

# storage/ is never uploaded; make sure its structure exists (first deploy).
mkdir -p storage/app/private storage/app/public \
    storage/framework/cache/data storage/framework/sessions storage/framework/views \
    storage/logs bootstrap/cache

credentials=$(grep -E '^FIREBASE_CREDENTIALS=' .env | cut -d= -f2- | tr -d '"' || true)
if [ -n "$credentials" ]; then
    case "$credentials" in /*) path="$credentials" ;; *) path="$(pwd)/$credentials" ;; esac
    [ -f "$path" ] || echo "WARNING: Firebase key $path not found; push notifications (FCM) will not be sent." >&2
fi

php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan queue:restart

if [ "$(id -u)" = "0" ]; then
    chown -R "$WEB_USER:$WEB_USER" storage bootstrap/cache
    [ -n "${PHP_FPM_SERVICE:-}" ] && systemctl reload "$PHP_FPM_SERVICE"
elif [ -n "${PHP_FPM_SERVICE:-}" ]; then
    sudo -n systemctl reload "$PHP_FPM_SERVICE" || echo "WARNING: could not reload $PHP_FPM_SERVICE." >&2
fi

php artisan up
echo "Deployed $(date -u +%Y-%m-%dT%H:%M:%SZ)"
