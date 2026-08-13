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

compose() {
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

cd "$ROOT_DIR"

compose config --quiet
compose build --pull app web

# Migrations run before new application/web containers are exposed.
compose run --rm --no-deps app php artisan migrate --force --no-interaction

compose up -d --remove-orphans

# Fail the deployment if internal provider/security readiness is not valid.
compose exec -T app php artisan ops:provider-readiness

STAGING_DOMAIN=$(sed -n 's/^STAGING_DOMAIN=//p' "$ENV_FILE" | tail -n 1)
if [ -z "$STAGING_DOMAIN" ]; then
    echo "STAGING_DOMAIN is missing." >&2
    exit 1
fi

attempt=1
while [ "$attempt" -le 30 ]; do
    if curl --fail --silent --show-error --max-time 5 "https://$STAGING_DOMAIN/api/v1/health" >/dev/null; then
        echo "Staging health check passed."
        exit 0
    fi

    sleep 2
    attempt=$((attempt + 1))
done

echo "Staging health check failed after 60 seconds." >&2
compose ps
exit 1
