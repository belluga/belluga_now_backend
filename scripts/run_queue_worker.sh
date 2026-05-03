#!/usr/bin/env sh
set -eu

exec php artisan queue:work \
  --queue="${QUEUE_WORKER_QUEUES:-otp,default}" \
  --sleep="${QUEUE_WORKER_SLEEP:-1}" \
  --tries="${QUEUE_WORKER_TRIES:-3}" \
  --timeout="${QUEUE_WORKER_TIMEOUT:-120}"
