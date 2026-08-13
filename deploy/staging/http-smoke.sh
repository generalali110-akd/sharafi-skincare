#!/bin/sh
set -eu

BASE_URL=${STAGING_APP_URL:-${1:-}}
if [ -z "$BASE_URL" ]; then
    echo "STAGING_APP_URL or a base URL argument is required." >&2
    exit 2
fi

case "$BASE_URL" in
    https://*) ;;
    *)
        echo "Staging smoke requires an HTTPS URL." >&2
        exit 2
        ;;
esac

BASE_URL=${BASE_URL%/}
HOST=${BASE_URL#https://}
case "$HOST" in
    */*)
        echo "STAGING_APP_URL must be an origin without a path." >&2
        exit 2
        ;;
esac

TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
    echo "[FAIL] $1" >&2
    exit 1
}

header_value() {
    header_name=$1
    header_file=$2
    awk -v name="$header_name" 'BEGIN { IGNORECASE=1 } index($0, name ":") == 1 { sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); value=$0 } END { print value }' "$header_file"
}

assert_header_contains() {
    header_name=$1
    needle=$2
    header_file=$3
    value=$(header_value "$header_name" "$header_file")
    printf '%s' "$value" | grep -Fqi -- "$needle" || fail "$header_name does not contain required value: $needle"
}

status=$(curl --silent --show-error --location --max-time 15 \
    --dump-header "$TMP_DIR/health.headers" \
    --output "$TMP_DIR/health.body" \
    --write-out '%{http_code}' \
    "$BASE_URL/api/v1/health")
[ "$status" = "200" ] || fail "HTTPS health endpoint returned HTTP $status"

assert_header_contains 'Strict-Transport-Security' 'max-age=' "$TMP_DIR/health.headers"
assert_header_contains 'X-Content-Type-Options' 'nosniff' "$TMP_DIR/health.headers"
assert_header_contains 'X-Frame-Options' 'SAMEORIGIN' "$TMP_DIR/health.headers"
assert_header_contains 'Referrer-Policy' 'strict-origin-when-cross-origin' "$TMP_DIR/health.headers"
assert_header_contains 'Permissions-Policy' 'camera=()' "$TMP_DIR/health.headers"

http_status=$(curl --silent --show-error --max-time 15 \
    --dump-header "$TMP_DIR/http.headers" \
    --output /dev/null \
    --write-out '%{http_code}' \
    "http://$HOST/api/v1/health")
case "$http_status" in
    301|302|307|308) ;;
    *) fail "HTTP endpoint did not redirect to HTTPS (HTTP $http_status)" ;;
esac
location=$(header_value 'Location' "$TMP_DIR/http.headers")
case "$location" in
    https://*) ;;
    *) fail 'HTTP redirect target is not HTTPS.' ;;
esac

me_status=$(curl --silent --show-error --max-time 15 \
    --dump-header "$TMP_DIR/me.headers" \
    --output "$TMP_DIR/me.body" \
    --write-out '%{http_code}' \
    -H 'Accept: application/json' \
    "$BASE_URL/api/v1/me")
[ "$me_status" = "401" ] || fail "Unauthenticated /api/v1/me returned HTTP $me_status instead of 401"
assert_header_contains 'Cache-Control' 'no-store' "$TMP_DIR/me.headers"

csrf_status=$(curl --silent --show-error --max-time 15 \
    --dump-header "$TMP_DIR/csrf.headers" \
    --output /dev/null \
    --write-out '%{http_code}' \
    -H "Origin: $BASE_URL" \
    -H 'Accept: application/json' \
    "$BASE_URL/sanctum/csrf-cookie")
[ "$csrf_status" = "204" ] || fail "CSRF cookie endpoint returned HTTP $csrf_status instead of 204"

assert_header_contains 'Access-Control-Allow-Origin' "$BASE_URL" "$TMP_DIR/csrf.headers"
assert_header_contains 'Access-Control-Allow-Credentials' 'true' "$TMP_DIR/csrf.headers"

grep -Eqi '^Set-Cookie:[[:space:]]*XSRF-TOKEN=' "$TMP_DIR/csrf.headers" || fail 'XSRF-TOKEN cookie was not issued.'
grep -Ei '^Set-Cookie:[[:space:]]*XSRF-TOKEN=' "$TMP_DIR/csrf.headers" | grep -Fqi 'Secure' || fail 'XSRF-TOKEN cookie is missing Secure.'
grep -Ei '^Set-Cookie:[[:space:]]*XSRF-TOKEN=' "$TMP_DIR/csrf.headers" | grep -Fqi 'SameSite=Lax' || fail 'XSRF-TOKEN cookie is missing SameSite=Lax.'

grep -Eqi '^Set-Cookie:[[:space:]]*sharafi_session=|^Set-Cookie:[[:space:]]*laravel_session=' "$TMP_DIR/csrf.headers" || fail 'Session cookie was not issued by the CSRF bootstrap.'
session_cookie=$(grep -Ei '^Set-Cookie:[[:space:]]*(sharafi_session|laravel_session)=' "$TMP_DIR/csrf.headers" | tail -n 1)
printf '%s' "$session_cookie" | grep -Fqi 'Secure' || fail 'Session cookie is missing Secure.'
printf '%s' "$session_cookie" | grep -Fqi 'HttpOnly' || fail 'Session cookie is missing HttpOnly.'
printf '%s' "$session_cookie" | grep -Fqi 'SameSite=Lax' || fail 'Session cookie is missing SameSite=Lax.'

untrusted_origin='https://cors-probe.invalid'
cors_status=$(curl --silent --show-error --max-time 15 \
    --dump-header "$TMP_DIR/cors.headers" \
    --output /dev/null \
    --write-out '%{http_code}' \
    -X OPTIONS \
    -H "Origin: $untrusted_origin" \
    -H 'Access-Control-Request-Method: GET' \
    -H 'Access-Control-Request-Headers: X-Requested-With' \
    "$BASE_URL/api/v1/me")
case "$cors_status" in
    200|204) ;;
    *) fail "CORS preflight returned unexpected HTTP $cors_status" ;;
esac

if grep -Eqi '^Access-Control-Allow-Origin:' "$TMP_DIR/cors.headers"; then
    fail 'Untrusted Origin received Access-Control-Allow-Origin.'
fi

echo '[OK] HTTPS, security headers, CSRF cookies and CORS boundaries passed.'
