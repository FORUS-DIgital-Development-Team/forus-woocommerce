#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID == 0 ]] || exit 1
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
# Reconciliation must run even when the demonstration store has no visitors.
exec 9>/run/lock/forus-merchant-cron.lock
flock -n 9 || exit 0
timeout 240 docker compose --env-file .env --env-file images.env run --rm -T cli cron event run --due-now
