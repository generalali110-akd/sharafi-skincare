#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
BASE_URL=${PRODUCTION_APP_URL:-${APP_URL:-${1:-}}}

exec env APP_URL="$BASE_URL" "$ROOT_DIR/deploy/common/http-smoke.sh"
