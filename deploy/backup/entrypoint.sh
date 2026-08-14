#!/bin/sh
set -eu

BACKUP_CRON=${BACKUP_CRON:-17 2 * * *}

if ! printf '%s\n' "$BACKUP_CRON" | grep -Eq '^[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+$'; then
    echo "BACKUP_CRON must be a five-field cron expression using digits and */,- only." >&2
    exit 1
fi

printf '%s %s\n' \
    "$BACKUP_CRON" \
    '/usr/local/bin/sharafi-backup >> /proc/1/fd/1 2>> /proc/1/fd/2' \
    > /etc/crontabs/root

exec crond -f -l 2
