#!/bin/sh
set -eu
if [ "$#" -ne 2 ] || [ ! -s "$1" ]; then
    echo 'Usage: scripts/restore-production.sh /protected/path/backup.dump exopulse_restore_UNIQUE' >&2
    exit 2
fi
restore_database=$2
case "$restore_database" in
    exopulse_restore_*) ;;
    *) echo 'Only a NEW database prefixed exopulse_restore_ is allowed.' >&2; exit 2 ;;
esac
case "$restore_database" in
    *[!a-z0-9_]*) echo 'Database name must contain only lowercase letters, digits and underscores.' >&2; exit 2 ;;
esac
if [ "${#restore_database}" -gt 63 ]; then
    echo 'Database name is too long.' >&2
    exit 2
fi
project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
# createdb fails if it exists. Never clean, drop or overwrite an existing database.
docker compose -f "$project_root/compose.prod.yaml" exec -T database sh -c 'exec createdb -U "$POSTGRES_USER" -O exopulse "$1"' sh "$restore_database"
docker compose -f "$project_root/compose.prod.yaml" exec -T database sh -c 'exec pg_restore -U "$POSTGRES_USER" -d "$1" --exit-on-error --no-owner --role=exopulse' sh "$restore_database" < "$1"
echo 'Restored into a new isolated database. Verify it before changing DATABASE_URL. The original database is untouched.'
