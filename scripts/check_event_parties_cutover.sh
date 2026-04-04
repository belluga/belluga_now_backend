#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

status=0

scan() {
  local label="$1"
  local pattern="$2"
  shift 2
  local output
  output="$(rg -n "$pattern" "$@" || true)"
  if [[ -n "$output" ]]; then
    status=1
    echo "[FAIL] $label"
    echo "$output"
    echo
  else
    echo "[OK] $label"
  fi
}

scan_filtered() {
  local label="$1"
  local include_pattern="$2"
  local exclude_pattern="$3"
  shift 3
  local output
  output="$(rg -n "$include_pattern" "$@" | rg -v "$exclude_pattern" || true)"
  if [[ -n "$output" ]]; then
    status=1
    echo "[FAIL] $label"
    echo "$output"
    echo
  else
    echo "[OK] $label"
  fi
}

echo "Laravel event-parties cutover scan"
echo

scan_filtered "write path still accepts or emits artist_ids outside rejection/backfill paths" \
  '\bartist_ids\b' \
  'prohibited|target_artist_ids|canonical_artist_ids|README' \
  packages/belluga/belluga_events/src \
  app

scan_filtered "runtime still materializes venue as event_party" \
  'party_type[^\n]*venue|VenueEventPartyMapper' \
  "must not be persisted|not_in:venue|=== 'venue'" \
  packages/belluga/belluga_events/src \
  app

scan "event query still derives linked profiles with artists/venue fallback arguments" \
  'resolveLinkedAccountProfiles\([^)]*,|resolveLinkedAccountProfiles\([^)]*\$artists|resolveLinkedAccountProfiles\([^)]*\$venue' \
  packages/belluga/belluga_events/src/Application/Events/EventQueryService.php

exit "$status"
