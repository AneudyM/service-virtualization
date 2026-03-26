#!/bin/bash
set -e

# Generate .env from Docker environment variables
# (Symfony Dotenv's loadEnv() expects this file to exist)
cat > /var/www/html/.env << ENVEOF
DB_HOST=${DB_HOST:-virtual-db}
DB_PORT=${DB_PORT:-3306}
DB_NAME=${DB_NAME:-service_virtualization}
DB_USER=${DB_USER:-virtual}
DB_PASS=${DB_PASS:-virtual}
APP_ENV=${APP_ENV:-local}
APP_DEBUG=${APP_DEBUG:-true}
APP_SECRET=${APP_SECRET:-local-dev-secret}
APP_BASE_URL=${APP_BASE_URL:-http://localhost:8080}
APP_INTERNAL_URL=${APP_INTERNAL_URL:-http://localhost}
AIPRISE_HMAC_KEY=${AIPRISE_HMAC_KEY:-virtual-aiprise-test-key}
AIPRISE_AUTO_DELAY=${AIPRISE_AUTO_DELAY:-10}
AIPRISE_CALLBACK_REWRITE_HOST=${AIPRISE_CALLBACK_REWRITE_HOST:-}
LOG_LEVEL=${LOG_LEVEL:-debug}
ENVEOF

echo "[entrypoint] Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."
MAX_WAIT=60
WAITED=0
until php -r "
    try {
        new PDO(
            'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT'),
            getenv('DB_USER'),
            getenv('DB_PASS')
        );
        echo 'connected';
    } catch (Exception \$e) {
        exit(1);
    }
" 2>/dev/null; do
    WAITED=$((WAITED + 2))
    if [ $WAITED -ge $MAX_WAIT ]; then
        echo "[entrypoint] ERROR: MySQL not ready after ${MAX_WAIT}s"
        exit 1
    fi
    sleep 2
done

echo "[entrypoint] MySQL ready. Installing schema..."
php bin/install-schema.php

echo "[entrypoint] Starting background callback firer (every 5s)..."
(while true; do
    sleep 5
    php /var/www/html/bin/fire-callbacks.php 2>/dev/null || true
done) &

echo "[entrypoint] Starting Apache..."
exec "$@"
