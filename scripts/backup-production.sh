#!/bin/sh
set -eu
umask 077
if [ "$#" -ne 1 ]; then
    echo 'Usage: scripts/backup-production.sh /protected/path/backup.dump' >&2
    exit 2
fi
project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
backup_path=$1
# Refuse overwriting an existing backup, including a symlink.
(set -C; docker compose -f "$project_root/compose.prod.yaml" exec -T database sh -c 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$backup_path")
if [ ! -s "$backup_path" ]; then
    echo 'Backup failed or empty; no valid archive was produced.' >&2
    exit 1
fi
docker compose -f "$project_root/compose.prod.yaml" exec -T database pg_restore --list < "$backup_path" > /dev/null
echo 'Backup written. Store it encrypted outside the repository and test restoration.'
