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
require_env BACKUP_AGE_RECIPIENT

DB_PORT=${DB_PORT:-5432}
BACKUP_DIR=${BACKUP_DIR:-/backups}
BACKUP_RETENTION_DAYS=${BACKUP_RETENTION_DAYS:-14}

case "$BACKUP_RETENTION_DAYS" in
    ''|*[!0-9]*)
        echo "BACKUP_RETENTION_DAYS must be a positive integer." >&2
        exit 1
        ;;
esac

if [ "$BACKUP_RETENTION_DAYS" -lt 1 ] || [ "$BACKUP_RETENTION_DAYS" -gt 3650 ]; then
    echo "BACKUP_RETENTION_DAYS must be between 1 and 3650." >&2
    exit 1
fi

umask 077
mkdir -p "$BACKUP_DIR"

timestamp=$(date -u '+%Y%m%dT%H%M%SZ')
filename="sharafi-$timestamp.dump.age"
final="$BACKUP_DIR/$filename"
tmp="$final.tmp"
checksum="$final.sha256"

cleanup() {
    rm -f "$tmp"
}
trap cleanup EXIT HUP INT TERM

export PGPASSWORD="$DB_PASSWORD"
export PGCONNECT_TIMEOUT=${PGCONNECT_TIMEOUT:-5}

if ! pg_dump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --username="$DB_USERNAME" \
    --format=custom \
    --no-owner \
    --no-privileges \
    "$DB_DATABASE" \
    | age --recipient "$BACKUP_AGE_RECIPIENT" --output "$tmp"; then
    echo "Encrypted PostgreSQL backup failed." >&2
    exit 1
fi

mv "$tmp" "$final"
(
    cd "$BACKUP_DIR"
    sha256sum "$filename" > "$filename.sha256"
)

find "$BACKUP_DIR" -type f \
    \( -name 'sharafi-*.dump.age' -o -name 'sharafi-*.dump.age.sha256' \) \
    -mtime "+$BACKUP_RETENTION_DAYS" -delete

printf 'Encrypted backup created: %s\n' "$final"
