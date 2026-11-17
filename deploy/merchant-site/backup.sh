#!/usr/bin/env bash
set -Eeuo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
[[ $EUID == 0 ]] || exit 1
umask 077
dc() { docker compose --env-file .env --env-file images.env "$@"; }
mkdir -p backups
stamp=$(date -u +%Y%m%dT%H%M%SZ)
dest="backups/$stamp"
mkdir "$dest"
dc exec -T db sh -c 'export MYSQL_PWD="$(cat /run/secrets/db_root_password)"; exec mariadb-dump --user=root --single-transaction --routines --events wordpress' | gzip > "$dest/database.sql.gz"
tar -czf "$dest/site-files.tar.gz" state/wordpress .env images.env secrets
test -s "$dest/database.sql.gz"
test -s "$dest/site-files.tar.gz"
gzip -t "$dest/database.sql.gz" "$dest/site-files.tar.gz"
echo "Backup complete: $dest. Root-only; includes secrets. Keep off-host disk snapshots as well."
# No automatic deletion. Retention must be set after verifying the first restore.
