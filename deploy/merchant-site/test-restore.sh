#!/usr/bin/env bash
set -Eeuo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
[[ $EUID == 0 && ${FORUS_LOCAL_TEST:-0} == 1 ]] || exit 1
grep -Fxq 'SITE_URL=http://localhost:8088' .env
dc() { docker compose --env-file .env --env-file images.env "$@"; }
source_dir=$(find backups -mindepth 1 -maxdepth 1 -type d | sort | tail -1)
test -s "$source_dir/database.sql.gz"
dc exec -T db sh -c 'export MYSQL_PWD="$(cat /run/secrets/db_root_password)"; exec mariadb -uroot' <<'SQL'
CREATE DATABASE restore_test;
SQL
gzip -dc "$source_dir/database.sql.gz" | dc exec -T db sh -c 'export MYSQL_PWD="$(cat /run/secrets/db_root_password)"; exec mariadb -uroot restore_test'
result=$(dc exec -T db sh -c 'export MYSQL_PWD="$(cat /run/secrets/db_root_password)"; exec mariadb -uroot -N restore_test' <<'SQL'
SELECT option_value FROM wp_options WHERE option_name = 'blogname';
SQL
)
[[ $result == 'FORUS Merchant Demo' ]] || exit 1
echo 'PASS database backup restored into separate disposable database.'
