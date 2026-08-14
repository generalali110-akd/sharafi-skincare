#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
ENV_FILE="$ROOT_DIR/SkinCare/.env.staging"
COMPOSE_FILE="$ROOT_DIR/deploy/staging/compose.yml"

if [ ! -f "$ENV_FILE" ]; then
    echo "Missing SkinCare/.env.staging" >&2
    exit 1
fi

case "$(uname -s)" in
    Linux)
        mode=$(stat -c '%a' "$ENV_FILE")
        case "$mode" in
            600|400) ;;
            *)
                echo "SkinCare/.env.staging must have permissions 600 or 400 (current: $mode)." >&2
                exit 1
                ;;
        esac
        ;;
esac

env_value() {
    key=$1
    sed -n "s/^${key}=//p" "$ENV_FILE" | tail -n 1 | sed 's/^"//; s/"$//'
}

STAGING_DOMAIN=$(env_value STAGING_DOMAIN)
BACKUP_AGE_RECIPIENT=$(env_value BACKUP_AGE_RECIPIENT)

if [ -z "$STAGING_DOMAIN" ]; then
    echo "STAGING_DOMAIN is missing." >&2
    exit 1
fi

if [ -z "$BACKUP_AGE_RECIPIENT" ]; then
    echo "BACKUP_AGE_RECIPIENT is required; deploys without encrypted backups are blocked." >&2
    exit 1
fi

compose() {
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

wait_for_database() {
    attempt=1
    while [ "$attempt" -le 30 ]; do
        if compose exec -T db sh -c 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"' >/dev/null 2>&1; then
            return 0
        fi

        sleep 2
        attempt=$((attempt + 1))
    done

    echo "PostgreSQL did not become ready within 60 seconds." >&2
    compose logs --no-color db >&2 || true
    return 1
}

wait_for_app() {
    attempt=1
    while [ "$attempt" -le 30 ]; do
        container_id=$(compose ps -q app)
        if [ -n "$container_id" ]; then
            status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id" 2>/dev/null || true)
            if [ "$status" = "healthy" ]; then
                return 0
            fi
        fi

        sleep 2
        attempt=$((attempt + 1))
    done

    echo "Application container did not become healthy within 60 seconds." >&2
    compose logs --no-color app >&2 || true
    return 1
}

service_is_running() {
    service=$1
    container_id=$(compose ps -q "$service")
    [ -n "$container_id" ] || return 1
    [ "$(docker inspect --format '{{.State.Status}}' "$container_id" 2>/dev/null || true)" = "running" ]
}

wait_for_runtime_health() {
    attempt=1
    while [ "$attempt" -le 45 ]; do
        if service_is_running queue \
            && service_is_running scheduler \
            && compose exec -T app php artisan ops:runtime-health --json >/dev/null 2>&1; then
            return 0
        fi

        sleep 2
        attempt=$((attempt + 1))
    done

    echo "Runtime health did not become ready within 90 seconds." >&2
    compose exec -T app php artisan ops:runtime-health --json >&2 || true
    compose logs --no-color app queue scheduler >&2 || true
    return 1
}

cd "$ROOT_DIR"

compose config --quiet
compose build --pull app web backup

# PostgreSQL 18 is private to the Docker network; wait for an actual database response.
compose up -d db
wait_for_database

# Migrations finish before the new application/web containers are exposed.
compose run --rm app php artisan migrate --force --no-interaction

compose up -d --remove-orphans
wait_for_app
wait_for_runtime_health

# Fail the deployment if internal provider/security readiness is not valid.
compose exec -T app php artisan ops:provider-readiness

# Every successful staging deploy must prove that an encrypted database backup can be created.
compose run --rm --entrypoint /usr/local/bin/sharafi-backup backup

attempt=1
while [ "$attempt" -le 30 ]; do
    if curl --fail --silent --show-error --max-time 5 "https://$STAGING_DOMAIN/api/v1/health" >/dev/null; then
        echo "Staging health check passed."
        exit 0
    fi

    sleep 2
    attempt=$((attempt + 1))
done

echo "Staging HTTPS health check failed after 60 seconds." >&2
compose ps >&2 || true
compose logs --no-color web app queue scheduler backup >&2 || true
exit 1
