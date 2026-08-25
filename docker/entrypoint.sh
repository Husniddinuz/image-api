#!/bin/sh
set -e

# Wait for the database before touching migrations; compose starts the app as
# soon as Postgres accepts connections, which is a moment before it is ready.
if [ -n "$DB_HOST" ]; then
    printf 'Waiting for database at %s:%s' "$DB_HOST" "${DB_PORT:-5432}"
    until php -r "exit(@fsockopen(getenv('DB_HOST'), (int) (getenv('DB_PORT') ?: 5432)) ? 0 : 1);" 2>/dev/null; do
        printf '.'
        sleep 1
    done
    echo ' ready.'
fi

if [ ! -f /app/.env ]; then
    cp /app/.env.example /app/.env
fi

if ! grep -q '^APP_KEY=base64:' /app/.env; then
    php artisan key:generate --force
fi

# Only the web container owns migrations; workers just wait for the schema.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
