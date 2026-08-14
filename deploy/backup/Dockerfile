FROM postgres:18.4-alpine

RUN apk add --no-cache age coreutils

COPY deploy/backup/backup.sh /usr/local/bin/sharafi-backup
COPY deploy/backup/restore-verify.sh /usr/local/bin/sharafi-restore-verify
COPY deploy/backup/entrypoint.sh /usr/local/bin/sharafi-backup-entrypoint

RUN chmod 0755 \
    /usr/local/bin/sharafi-backup \
    /usr/local/bin/sharafi-restore-verify \
    /usr/local/bin/sharafi-backup-entrypoint

ENTRYPOINT ["/usr/local/bin/sharafi-backup-entrypoint"]
