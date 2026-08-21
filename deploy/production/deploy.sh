#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
ENV_FILE="$ROOT_DIR/SkinCare/.env.production"
COMPOSE_FILE="$ROOT_DIR/deploy/production/compose.yml"
STATE_DIR=${SHARAFI_RELEASE_STATE_DIR:-"$ROOT_DIR/.deploy-state/production"}
CURRENT_TAG_FILE="$STATE_DIR/current-image-tag"
PREVIOUS_TAG_FILE="$STATE_DIR/previous-image-tag"

fail() {
    echo "[FAIL] $1" >&2
    exit 1
}

[ -f "$ENV_FILE" ] || fail 'Missing SkinCare/.env.production'

case "$(uname -s)" in
    Linux)
        mode=$(stat -c '%a' "$ENV_FILE")
        case "$mode" in
            600|400) ;;
            *) fail "SkinCare/.env.production must have permissions 600 or 400 (current: $mode)." ;;
        esac
        ;;
esac

env_value() {
    key=$1
    sed -n "s/^${key}=//p" "$ENV_FILE" | tail -n 1 | sed 's/^"//; s/"$//'
}

require_equals() {
    key=$1
    expected=$2
    value=$(env_value "$key")
    [ "$value" = "$expected" ] || fail "$key must be $expected for production (current: ${value:-<empty>})."
}

APP_URL=$(env_value APP_URL)
PRODUCTION_DOMAIN=$(env_value PRODUCTION_DOMAIN)
IMAGE_TAG=$(env_value IMAGE_TAG)
BACKUP_AGE_RECIPIENT=$(env_value BACKUP_AGE_RECIPIENT)

[ -n "$PRODUCTION_DOMAIN" ] || fail 'PRODUCTION_DOMAIN is required.'
[ "$APP_URL" = "https://$PRODUCTION_DOMAIN" ] || fail 'APP_URL must exactly match https://PRODUCTION_DOMAIN.'
[ -n "$BACKUP_AGE_RECIPIENT" ] || fail 'BACKUP_AGE_RECIPIENT is required; production deploy without encrypted backup is blocked.'
[ -n "$IMAGE_TAG" ] || fail 'IMAGE_TAG is required and must identify an immutable release.'
case "$IMAGE_TAG" in
    latest|production|staging) fail 'IMAGE_TAG must be immutable; latest/production/staging are forbidden.' ;;
    *[!A-Za-z0-9._-]*) fail 'IMAGE_TAG contains unsafe characters.' ;;
esac

require_equals APP_ENV production
require_equals APP_DEBUG false
require_equals SESSION_SECURE_COOKIE true
require_equals SESSION_ENCRYPT true
require_equals SMS_DRIVER smsir
require_equals SMSIR_SANDBOX false
require_equals PAYMENT_DRIVER zarinpal
require_equals ZARINPAL_SANDBOX false

compose() {
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

service_is_running() {
    service=$1
    container_id=$(compose ps -q "$service" 2>/dev/null || true)
    [ -n "$container_id" ] || return 1
    [ "$(docker inspect --format '{{.State.Status}}' "$container_id" 2>/dev/null || true)" = running ]
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
    compose logs --no-color db >&2 || true
    fail 'PostgreSQL did not become ready within 60 seconds.'
}

wait_for_app() {
    attempt=1
    while [ "$attempt" -le 30 ]; do
        container_id=$(compose ps -q app)
        if [ -n "$container_id" ]; then
            status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id" 2>/dev/null || true)
            [ "$status" = healthy ] && return 0
        fi
        sleep 2
        attempt=$((attempt + 1))
    done
    compose logs --no-color app >&2 || true
    fail 'Application did not become healthy within 60 seconds.'
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
    compose exec -T app php artisan ops:runtime-health --json >&2 || true
    compose logs --no-color app queue scheduler >&2 || true
    fail 'Runtime health did not become ready within 90 seconds.'
}

on_failure() {
    code=$?
    trap - EXIT HUP INT TERM
    echo '[FAIL] Production deployment did not complete.' >&2
    if service_is_running app; then
        compose exec -T app php artisan down --retry=60 --refresh=15 >/dev/null 2>&1 || true
    fi
    if [ -s "$CURRENT_TAG_FILE" ]; then
        previous=$(cat "$CURRENT_TAG_FILE")
        echo "Previous successful image tag: $previous" >&2
        echo "Review migration compatibility, then run: deploy/production/rollback.sh $previous" >&2
    else
        echo 'No previous successful release tag is recorded. Use backup/restore runbook if the database changed.' >&2
    fi
    compose ps >&2 || true
    compose logs --no-color app queue scheduler web backup >&2 || true
    exit "$code"
}
trap on_failure EXIT HUP INT TERM

mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR" 2>/dev/null || true

cd "$ROOT_DIR"
compose config --quiet

# Build immutable-tagged release images before touching the running application.
compose build --pull app web backup

# Keep the database private and prove it is responsive.
compose up -d db
wait_for_database

# A production migration is blocked unless an encrypted pre-migration backup succeeds.
compose run --rm --entrypoint /usr/local/bin/sharafi-backup backup

# Enter maintenance only after the backup exists. First deploys may not have an app container yet.
if service_is_running app; then
    compose exec -T app php artisan down --retry=60 --refresh=15
fi

compose run --rm app php artisan migrate --force --no-interaction
compose up -d --remove-orphans
wait_for_app
wait_for_runtime_health
compose exec -T app php artisan ops:provider-readiness
compose exec -T app php artisan up

APP_URL="$APP_URL" "$ROOT_DIR/deploy/common/http-smoke.sh"

if [ -s "$CURRENT_TAG_FILE" ]; then
    cp "$CURRENT_TAG_FILE" "$PREVIOUS_TAG_FILE"
fi
printf '%s\n' "$IMAGE_TAG" > "$CURRENT_TAG_FILE"
chmod 600 "$CURRENT_TAG_FILE" "$PREVIOUS_TAG_FILE" 2>/dev/null || true

trap - EXIT HUP INT TERM
echo "[OK] Production deployment completed for image tag $IMAGE_TAG."
