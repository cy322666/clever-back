#!/bin/sh
set -e

if [ ! -f /var/www/html/vendor/autoload.php ] && [ -d /opt/vendor ]; then
    mkdir -p /var/www/html/vendor
    cp -R /opt/vendor/. /var/www/html/vendor/
fi

if [ ! -f /var/www/html/public/build/manifest.json ] && [ -d /opt/build ]; then
    mkdir -p /var/www/html/public/build
    cp -R /opt/build/. /var/www/html/public/build/
fi

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    GENERATED_APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
    if [ -f /var/www/html/.env ]; then
        if grep -q '^APP_KEY=' /var/www/html/.env; then
            sed -i.bak "s#^APP_KEY=.*#APP_KEY=${GENERATED_APP_KEY}#" /var/www/html/.env
            rm -f /var/www/html/.env.bak
        else
            printf '\nAPP_KEY=%s\n' "$GENERATED_APP_KEY" >> /var/www/html/.env
        fi
    fi
    export APP_KEY="$GENERATED_APP_KEY"
fi

if [ -n "$DB_HOST" ]; then
    echo "Waiting for database at ${DB_HOST}:${DB_PORT:-5432}..."
    for attempt in $(seq 1 30); do
        if php -r '$host=getenv("DB_HOST"); $port=getenv("DB_PORT") ?: 5432; $db=getenv("DB_DATABASE"); $user=getenv("DB_USERNAME"); $pass=getenv("DB_PASSWORD"); try { new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass); exit(0); } catch (Throwable $e) { exit(1); }'; then
            break
        fi

        sleep 2
    done
fi

exec "$@"
