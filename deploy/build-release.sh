#!/usr/bin/env bash
# Builds the production artifact of a release from the tracked files of HEAD:
# source + production Composer dependencies + manifest.json, packed as
# dist/rumput-bank-mini-api-v<version>.tar.gz with a .sha256 file next to it.
# Used by .github/workflows/release.yml; can also be run locally.
# Usage: deploy/build-release.sh 1.0.0
set -euo pipefail

version="${1:?usage: $0 <version, e.g. 1.0.0>}"
root="$(git rev-parse --show-toplevel)"
cd "$root"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Commit or stash your changes first: the artifact is built from HEAD." >&2
    exit 1
fi

name="rumput-bank-mini-api-v${version}"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$root/dist" "$work/build"

git archive HEAD | tar -x -C "$work/build"
cd "$work/build"

# Not needed to run the app.
rm -rf tests docs tools .github .claude deploy phpunit.xml .phpactor.json .editorconfig \
    .gitattributes .gitignore PRD.md RENCANA_PENGEMBANGAN.md package.json package-lock.json \
    vite.config.js .npmrc resources/js resources/css

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress
# Generated on the server by `artisan optimize`.
rm -f bootstrap/cache/*.php

# Stop if anything environment-specific or secret slipped in (vendor is skipped).
sensitive="$(find . -path ./vendor -prune -o \( -name '.env' -o -name '*.sql' -o -name '*.pem' \
    -o -name '*adminsdk*' -o -name '*credentials*.json' \) -print)"
sensitive="$sensitive$(grep -rIl -E 'BEGIN ([A-Z]+ )?PRIVATE KEY|"private_key"' . --exclude-dir=vendor || true)"
if [ -n "$sensitive" ]; then
    echo "Sensitive files in the artifact:" >&2
    echo "$sensitive" >&2
    exit 1
fi

COMMIT="$(git -C "$root" rev-parse HEAD)" VERSION="$version" python3 - <<'PY'
import datetime, json, os, re, subprocess

lock = json.load(open("composer.lock"))
laravel = next(p["version"] for p in lock["packages"] if p["name"] == "laravel/framework")

# The lowest PHP the locked dependencies allow, as enforced at runtime by vendor/composer/platform_check.php.
php_id = int(re.search(r"PHP_VERSION_ID >= (\d+)", open("vendor/composer/platform_check.php").read()).group(1))
php = f">={php_id // 10000}.{php_id // 100 % 100}.{php_id % 100}"

reqs = json.loads(subprocess.run(["composer", "check-platform-reqs", "--no-dev", "--format=json"],
                                 capture_output=True, text=True).stdout)
extensions = sorted({r["name"][4:] for r in reqs if r["name"].startswith("ext-") and not r.get("provider")}
                    | {"pdo_mysql", "curl", "mbstring", "ctype"})

manifest = {
    "name": "rumput-bank-mini-api",
    "version": os.environ["VERSION"],
    "commit": os.environ["COMMIT"],
    "built_at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    "php_requirement": php,
    "php_extensions": extensions,
    "laravel_version": laravel,
    "repository": "https://github.com/Adib-Fathurrohman/UKK-Bank-Mini",
}
open("manifest.json", "w").write(json.dumps(manifest, indent=2) + "\n")
PY

if tar --version | grep -q GNU; then
    owner=(--owner=0 --group=0 --numeric-owner)
else
    owner=(--uid 0 --gid 0 --uname root --gname root --no-mac-metadata)
fi
COPYFILE_DISABLE=1 tar "${owner[@]}" -czf "$root/dist/$name.tar.gz" .

cd "$root/dist"
if command -v sha256sum >/dev/null; then sha256sum "$name.tar.gz" > "$name.tar.gz.sha256"; else shasum -a 256 "$name.tar.gz" > "$name.tar.gz.sha256"; fi
cp "$work/build/manifest.json" "$name.manifest.json"
cat "$name.tar.gz.sha256"
