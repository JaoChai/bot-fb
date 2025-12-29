#!/bin/sh
set -e

echo "=== Starting Reverb WebSocket Server ===" >&2
echo "PORT: ${PORT:-8080}" >&2
echo "REVERB_APP_ID: ${REVERB_APP_ID:-NOT_SET}" >&2
echo "REVERB_HOST: ${REVERB_HOST:-NOT_SET}" >&2
echo "REVERB_PORT: ${REVERB_PORT:-NOT_SET}" >&2
echo "REVERB_SCHEME: ${REVERB_SCHEME:-NOT_SET}" >&2

echo "=== Caching config ===" >&2
php artisan config:cache

echo "=== Starting reverb:start ===" >&2
exec php artisan reverb:start --host=0.0.0.0 --port="${PORT:-8080}" --debug
