#!/bin/sh
set -eu

require_env() {
    name=$1
    eval "value=\${$name:-}"
    if [ -z "$value" ]; then
        echo "$name is required." >&2
        exit 1
    fi
}

require_env DB_HOST
require_env DB_DATABASE
require_env DB_USERNAME
require_env DB_PASSWORD
require_env BACKUP_AGE_IDENTITY_FILE

DB_PORT=${DB_PORT:-5432}
RESTORE_VERIFY_DATABASE=${RESTORE_VERIFY_DATABASE:-sharafi_restore_verify}
BACKUP_FILE=${1:-}

if [ -z "$BACKUP_FILE" ] || [ ! -f "$BACKUP_FILE" ]; then
    echo "Usage: sharafi-restore-verify /backups/sharafi-YYYYmmddTHHMMSSZ.dump.age" >&2
    exit 2
fi

if [ ! -f "$BACKUP_AGE_IDENTITY_FILE" ]; then
    echo "BACKUP_AGE_IDENTITY_FILE does not exist." >&2
    exit 1
fi

case "$RESTORE_VERIFY_DATABASE" in
    ''|*[!A-Za-z0-9_]*)
        echo "RESTORE_VERIFY_DATABASE may only contain letters, digits, and underscores." >&2
        exit 1
        ;;
esac

if [ "$RESTORE_VERIFY_DATABASE" = "$DB_DATABASE" ]; then
    echo "Restore verification database must differ from DB_DATABASE." >&2
    exit 1
fi

checksum="$BACKUP_FILE.sha256"
if [ -f "$checksum" ]; then
    (cd "$(dirname "$BACKUP_FILE")" && sha256sum -c "$(basename "$checksum")")
fi

export PGPASSWORD="$DB_PASSWORD"
export PGCONNECT_TIMEOUT=${PGCONNECT_TIMEOUT:-5}

psql_admin() {
    psql \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --username="$DB_USERNAME" \
        --dbname=postgres \
        --set=ON_ERROR_STOP=1 \
        "$@"
}

cleanup() {
    psql_admin --command="DROP DATABASE IF EXISTS \"$RESTORE_VERIFY_DATABASE\" WITH (FORCE);" >/dev/null 2>&1 || true
}
trap cleanup EXIT HUP INT TERM

cleanup
psql_admin --command="CREATE DATABASE \"$RESTORE_VERIFY_DATABASE\";" >/dev/null

if ! age --decrypt --identity "$BACKUP_AGE_IDENTITY_FILE" "$BACKUP_FILE" \
    | pg_restore \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --username="$DB_USERNAME" \
        --dbname="$RESTORE_VERIFY_DATABASE" \
        --no-owner \
        --no-privileges \
        --exit-on-error; then
    echo "Restore verification failed during pg_restore." >&2
    exit 1
fi

migration_table=$(psql \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --username="$DB_USERNAME" \
    --dbname="$RESTORE_VERIFY_DATABASE" \
    --tuples-only \
    --no-align \
    --set=ON_ERROR_STOP=1 \
    --command="SELECT to_regclass('public.migrations') IS NOT NULL;")

if [ "$migration_table" != "t" ]; then
    echo "Restore verification failed: migrations table is missing." >&2
    exit 1
fi

printf 'Restore verification passed for %s into isolated database %s.\n' "$BACKUP_FILE" "$RESTORE_VERIFY_DATABASE"
