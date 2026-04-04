#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

dry_run_flag="${1:-}"
if [[ "$dry_run_flag" == "--dry-run" ]]; then
  php artisan events:legacy-event-parties:repair --dry-run
  exit 0
fi

php artisan events:legacy-event-parties:repair
