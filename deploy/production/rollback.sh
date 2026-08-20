#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
ENV_FILE="$ROOT_DIR/SkinCare/.env.production"
COMPOSE_FILE="$ROOT_DIR/deploy/production/compose.yml"
STATE_DIR=${SHARAFI_RELEASE_STATE_DIR:-"$ROOT_DIR/.deploy-state/production"}
CURRENT_TAG_FILE="$STATE_DIR/current-image-tag"
PREVIOUS_TAG_FILE="$STATE_DIR/previous-image-tag"
TARGET_TAG=${1:-}

fail() {
    echo "[FAIL] $1" >&2
    exit 1
}

[ -f "$ENV_FILE" ] || fail 'Missing SkinCare/.env.production'

if [ -z "$TARGET_TAG" ] && [ -s "$PREVIOUS_TAG_FILE" ]; then
    TARGET_TAG=$(cat "$PREVIOUS_TAG_FILE")
fi
[ -n "$TARGET_TAG" ] || fail 'Provide the target image tag or ensure previous-image-tag exists.'
case "$TARGET_TAG" in
    latest|production|staging|*[!A-Za-z0-9._-]*) fail 'Rollback target must be an immutable safe image tag.' ;;
esac

APP_URL=$(sed -n 's/^APP_URL=//p' "$ENV_FILE" | tail -n 1 | sed 's/^"//; s/"$//')
[ -n "$APP_URL" ] || fail 'APP_URL is required.'

for image in sharafi-skincare-app sharafi-skincare-web sharafi-skincare-backup; do
    docker image inspect "$image:$TARGET_TAG" >/dev/null 2>&1 \
        || fail "Required rollback image is not present locally: $image:$TARGET_TAG"
done

export IMAGE_TAG=$TARGET_TAG
compose() {
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

cd "$ROOT_DIR"
compose config --quiet
compose up -d db

# Preserve the current database before switching application images.
compose run --rm --entrypoint /usr/local/bin/sharafi-backup backup

if current_app=$(compose ps -q app 2>/dev/null) && [ -n "$current_app" ]; then
    compose exec -T app php artisan down --retry=60 --refresh=15 || true
fi

# Deliberately do not run migrate:rollback. Database rollback is destructive and must
# follow the restore runbook when migrations are not backward-compatible.
compose up -d --no-build --remove-orphans app queue scheduler backup web

attempt=1
while [ "$attempt" -le 30 ]; do
    app_id=$(compose ps -q app)
    if [ -n "$app_id" ]; then
        status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$app_id" 2>/dev/null || true)
        [ "$status" = healthy ] && break
    fi
    [ "$attempt" -lt 30 ] || fail 'Rollback application did not become healthy.'
    sleep 2
    attempt=$((attempt + 1))
done

compose exec -T app php artisan ops:runtime-health
compose exec -T app php artisan up
APP_URL="$APP_URL" "$ROOT_DIR/deploy/common/http-smoke.sh"

mkdir -p "$STATE_DIR"
if [ -s "$CURRENT_TAG_FILE" ]; then
    cp "$CURRENT_TAG_FILE" "$PREVIOUS_TAG_FILE"
fi
printf '%s\n' "$TARGET_TAG" > "$CURRENT_TAG_FILE"
chmod 600 "$CURRENT_TAG_FILE" "$PREVIOUS_TAG_FILE" 2>/dev/null || true

echo "[OK] Application images rolled back to $TARGET_TAG. Database migrations were not reversed."
