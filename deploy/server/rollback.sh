#!/usr/bin/env bash
# Points `current` back at an existing release. Run as root. Usage: rollback.sh 0.1.0
# Database migrations are NOT reverted; restore a backup from /var/www/rumput/bank-mini/backups if needed.
set -euo pipefail
V="${1:?usage: $0 <version>}"; V="${V#v}"
API=/var/www/rumput/bank-mini/api
[ -d "$API/releases/v$V" ] || { echo "No release v$V in $API/releases:" >&2; ls "$API/releases" >&2; exit 1; }
ln -sfn "releases/v$V" "$API/current.new" && mv -Tf "$API/current.new" "$API/current"
echo "Active release: $(readlink "$API/current")"
