#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_ROOT="$(cd "${REPO_ROOT}/.." && pwd)"

RUN_ID="${RUN_ID:-security-confidence-$(date +%Y%m%d-%H%M%S)}"
ARTIFACTS_DIR="${ARTIFACTS_DIR:-${ENV_ROOT}/foundation_documentation/artifacts/tmp/${RUN_ID}}"
BELLUGA_BASE_URL="${BELLUGA_BASE_URL:-https://belluga.space}"

mkdir -p "${ARTIFACTS_DIR}"

log() {
  printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"
}

run_and_capture() {
  local name="$1"
  shift

  log "Running ${name}"
  set +e
  "$@" >"${ARTIFACTS_DIR}/${name}.log" 2>&1
  local status=$?
  set -e
  log "${name} exit=${status}"
  return "${status}"
}

assert_contains() {
  local file="$1"
  local pattern="$2"
  local label="$3"
  if ! rg -qi -- "${pattern}" "${file}"; then
    echo "ASSERT FAIL: ${label} (pattern='${pattern}', file='${file}')" | tee -a "${ARTIFACTS_DIR}/assertions.log"
    return 1
  fi
  echo "ASSERT PASS: ${label}" >>"${ARTIFACTS_DIR}/assertions.log"
  return 0
}

extract_last_http_status() {
  local file="$1"
  awk '/^HTTP\//{code=$2} END{print code}' "${file}"
}

LOCAL_TEST_ENV=(
  APP_ENV=testing
  APP_KEY=base64:GmmALtgdmR+nNYciHr0ynX/QoqHXmoXXtbwHVNWg8Pk=
  APP_LOCALE=en
  APP_FALLBACK_LOCALE=en
  APP_FAKER_LOCALE=pt_BR
  DB_CONNECTION_LANDLORD=landlord
  DB_CONNECTION_TENANTS=tenant
  DB_URI=mongodb://mongo:27017/landlord_test?replicaSet=rs0
  DB_URI_LANDLORD=mongodb://mongo:27017/landlord_test?replicaSet=rs0
  DB_URI_TENANTS=mongodb://mongo:27017/tenants_test?replicaSet=rs0
  DB_DATABASE=landlord_test
  DB_DATABASE_LANDLORD=landlord_test
  DB_DATABASE_TENANTS=tenants_test
)

docker_php() {
  docker run --rm \
    --network belluga_now_docker_app-network \
    -e LOCAL_UID="$(id -u)" \
    -e LOCAL_GID="$(id -g)" \
    -v "${REPO_ROOT}:/workspace" \
    -w /workspace \
    belluga_now_docker-app:latest "$@"
}

docker_php_env() {
  docker run --rm \
    --network belluga_now_docker_app-network \
    -e LOCAL_UID="$(id -u)" \
    -e LOCAL_GID="$(id -g)" \
    -v "${REPO_ROOT}:/workspace" \
    -w /workspace \
    belluga_now_docker-app:latest env "${LOCAL_TEST_ENV[@]}" "$@"
}

run_and_capture "laravel_pint_security" \
  docker_php ./vendor/bin/pint --test tests/Feature/Security/ApiSecurityHardeningMiddlewareTest.php tests/Feature/Security/ApiAbuseSignalsControllerTest.php

run_and_capture "laravel_security_suite" \
  docker_php_env php artisan test tests/Feature/Security

run_and_capture "laravel_guardrails" \
  docker_php php scripts/architecture_guardrails.php

run_and_capture "belluga_root_head" \
  curl -sS -I "${BELLUGA_BASE_URL}"

run_and_capture "belluga_security_noauth_headers" \
  curl -sS -D - -o "${ARTIFACTS_DIR}/belluga_security_noauth_body.json" "${BELLUGA_BASE_URL}/admin/api/v1/security/abuse-signals"

run_and_capture "belluga_security_spoof_cf_headers" \
  curl -sS -D - -o "${ARTIFACTS_DIR}/belluga_security_spoof_cf_body.txt" \
    -H 'CF-Connecting-IP: 1.2.3.4' \
    -H 'X-Forwarded-For: 1.2.3.4' \
    "${BELLUGA_BASE_URL}/admin/api/v1/security/abuse-signals"

run_and_capture "belluga_security_spoof_xff_headers" \
  curl -sS -D - -o "${ARTIFACTS_DIR}/belluga_security_spoof_xff_body.txt" \
    -H 'X-Forwarded-For: 1.2.3.4' \
    "${BELLUGA_BASE_URL}/admin/api/v1/security/abuse-signals"

ASSERT_FAILED=0

assert_contains "${ARTIFACTS_DIR}/belluga_root_head.log" '^server:\s*cloudflare' 'Root response is served by Cloudflare' || ASSERT_FAILED=1
assert_contains "${ARTIFACTS_DIR}/belluga_root_head.log" '^cf-ray:' 'Root response exposes CF-Ray header' || ASSERT_FAILED=1

NOAUTH_STATUS="$(extract_last_http_status "${ARTIFACTS_DIR}/belluga_security_noauth_headers.log")"
case "${NOAUTH_STATUS}" in
  401|403|404) echo "ASSERT PASS: No-auth security endpoint status ${NOAUTH_STATUS}" >>"${ARTIFACTS_DIR}/assertions.log" ;;
  *) echo "ASSERT FAIL: Unexpected no-auth security endpoint status ${NOAUTH_STATUS}" | tee -a "${ARTIFACTS_DIR}/assertions.log"; ASSERT_FAILED=1 ;;
esac

SPOOF_CF_STATUS="$(extract_last_http_status "${ARTIFACTS_DIR}/belluga_security_spoof_cf_headers.log")"
case "${SPOOF_CF_STATUS}" in
  403|404) echo "ASSERT PASS: Spoofed CF header request blocked/non-success (${SPOOF_CF_STATUS})" >>"${ARTIFACTS_DIR}/assertions.log" ;;
  *) echo "ASSERT FAIL: Unexpected spoofed CF header status ${SPOOF_CF_STATUS}" | tee -a "${ARTIFACTS_DIR}/assertions.log"; ASSERT_FAILED=1 ;;
esac

SPOOF_XFF_STATUS="$(extract_last_http_status "${ARTIFACTS_DIR}/belluga_security_spoof_xff_headers.log")"
case "${SPOOF_XFF_STATUS}" in
  401|403|404) echo "ASSERT PASS: Spoofed XFF request non-success (${SPOOF_XFF_STATUS})" >>"${ARTIFACTS_DIR}/assertions.log" ;;
  *) echo "ASSERT FAIL: Unexpected spoofed XFF status ${SPOOF_XFF_STATUS}" | tee -a "${ARTIFACTS_DIR}/assertions.log"; ASSERT_FAILED=1 ;;
esac

{
  echo "run_id=${RUN_ID}"
  echo "artifacts_dir=${ARTIFACTS_DIR}"
  echo "belluga_base_url=${BELLUGA_BASE_URL}"
  echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "status_noauth=${NOAUTH_STATUS:-unknown}"
  echo "status_spoof_cf=${SPOOF_CF_STATUS:-unknown}"
  echo "status_spoof_xff=${SPOOF_XFF_STATUS:-unknown}"
  if [[ "${ASSERT_FAILED}" -eq 0 ]]; then
    echo "gate_result=PASS"
  else
    echo "gate_result=FAIL"
  fi
  echo "note=Inspect individual *.log files for command outputs."
} >"${ARTIFACTS_DIR}/summary.txt"

if [[ "${ASSERT_FAILED}" -ne 0 ]]; then
  log "Confidence pack finished with assertion failures. Artifacts at: ${ARTIFACTS_DIR}"
  exit 1
fi

log "Confidence pack finished. Artifacts at: ${ARTIFACTS_DIR}"
