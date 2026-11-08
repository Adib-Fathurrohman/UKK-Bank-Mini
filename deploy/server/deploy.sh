#!/usr/bin/env bash
# Deploys a GitHub release of the Rumput Bank Mini API. Run as root.
# Usage: deploy.sh 0.1.0
# Downloads the release, verifies its SHA-256, extracts it next to the previous
# releases (never overwriting them), backs up the database, migrates, builds the
# caches, health-checks the new release and only then switches `current`.
set -euo pipefail

V="${1:?usage: $0 <version, e.g. 0.1.0>}"; V="${V#v}"
APP=/var/www/rumput/bank-mini; API=$APP/api; REL=$API/releases/v$V
NAME=rumput-bank-mini-api-v$V
URL=https://github.com/Adib-Fathurrohman/UKK-Bank-Mini/releases/download/v$V
run() { runuser -u rumput -- "$@"; }

[ ! -e "$REL" ] || { echo "Release v$V already exists: $REL" >&2; exit 1; }

DL=$APP/tmp/download-v$V
install -d -o rumput -g rumput -m 700 "$DL"
curl -fsSL -o "$DL/$NAME.tar.gz" "$URL/$NAME.tar.gz"
curl -fsSL -o "$DL/$NAME.tar.gz.sha256" "$URL/$NAME.tar.gz.sha256"
(cd "$DL" && sha256sum -c "$NAME.tar.gz.sha256")

created=0
cleanup() { [ "$created" = 1 ] && [ -n "${REL:-}" ] && rm -rf -- "$REL"; }
trap 'echo "FAILED: removing the incomplete release; the active release is unchanged." >&2; cleanup' ERR

install -d -o rumput -g rumput -m 755 "$REL"; created=1
tar -xzf "$DL/$NAME.tar.gz" -C "$REL" --no-same-owner --no-same-permissions 2>/dev/null
chown -R rumput:rumput "$REL"
find "$REL" -type d -exec chmod 755 {} +; find "$REL" -type f -exec chmod 644 {} +; chmod 755 "$REL/artisan"

need=$(python3 -c "import json;print(json.load(open('$REL/manifest.json'))['php_requirement'].lstrip('>='))")
php -r "exit(version_compare(PHP_VERSION, '$need', '>=') ? 0 : 1);" || { echo "PHP $need or newer is required" >&2; false; }

rm -rf "$REL/storage"
ln -s "$API/shared/storage" "$REL/storage"; ln -s "$API/shared/.env" "$REL/.env"
chown -h rumput:rumput "$REL/storage" "$REL/.env"
cd "$REL"

B=$APP/backups/rumput_bank_mini-before-v$V-$(date -u +%Y%m%dT%H%M%SZ).sql
(umask 077; mariadb-dump --single-transaction --routines --triggers rumput_bank_mini > "$B"); chown rumput:rumput "$B"
echo "Database backup: $B"

run php artisan migrate --force
# TMPDIR keeps dompdf's temp files inside the project (the PHP-FPM pool's open_basedir).
TMPDIR=$APP/tmp run php artisan optimize

code=$(run php -r "require '$REL/vendor/autoload.php'; \$a = require '$REL/bootstrap/app.php'; echo \$a->make(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create('/up'))->getStatusCode();")
[ "$code" = 200 ] || { echo "Health check failed (/up -> $code)" >&2; false; }

trap - ERR
ln -sfn "releases/v$V" "$API/current.new" && mv -Tf "$API/current.new" "$API/current"
echo "Active release: $(readlink "$API/current")"
echo "Old releases are kept in $API/releases (rollback: $APP/bin/rollback.sh <version>)."
