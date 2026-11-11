#!/usr/bin/env bash
set -e

echo "[Docker] Initializing Laravel application..."

# 1. Ensure required storage & cache directories exist and have proper permissions
mkdir -p /var/www/html/storage/framework/{cache,sessions,views} \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 2. Check storage symlink
if [ ! -L /var/www/html/public/storage ]; then
    echo "[Docker] Linking public storage..."
    php /var/www/html/artisan storage:link --no-interaction || true
fi

# 3. Handle SQLite if configured
if [ "${DB_CONNECTION}" = "sqlite" ]; then
    DB_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    if [ ! -f "${DB_PATH}" ]; then
        echo "[Docker] Creating SQLite database file at ${DB_PATH}..."
        mkdir -p "$(dirname "${DB_PATH}")"
        touch "${DB_PATH}"
    fi
    chown www-data:www-data "${DB_PATH}"
    chmod 664 "${DB_PATH}"
fi

# 4. Optional background worker and scheduler toggle
if [ "${ENABLE_QUEUE_WORKER:-true}" = "false" ]; then
    echo "[Docker] Queue worker disabled."
    rm -f /etc/supervisor/conf.d/queue.conf
fi

if [ "${ENABLE_SCHEDULER:-true}" = "false" ]; then
    echo "[Docker] Scheduler disabled."
    rm -f /etc/supervisor/conf.d/scheduler.conf
fi

# 5. Wait for database and run migrations if AUTO_MIGRATE=true
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    if [ "${DB_CONNECTION}" != "sqlite" ] && [ -n "${DB_HOST}" ]; then
        PORT="${DB_PORT:-3306}"
        echo "[Docker] Waiting for database at ${DB_HOST}:${PORT}..."
        for i in {1..30}; do
            if (echo > /dev/tcp/"${DB_HOST}"/"${PORT}") 2>/dev/null; then
                echo "[Docker] Database connection established!"
                break
            fi
            echo "[Docker] Database not ready yet, retrying in 2s... ($i/30)"
            sleep 2
        done
    fi

    echo "[Docker] Running database migrations..."
    php /var/www/html/artisan migrate --force --no-interaction || {
        echo "[Docker] Warning: Migration failed. Please inspect database configuration."
    }
fi

# 6. Production optimization
if [ "${APP_ENV:-production}" = "production" ]; then
    echo "[Docker] Caching Laravel configuration, routes, and views..."
    php /var/www/html/artisan config:cache || true
    php /var/www/html/artisan route:cache || true
    php /var/www/html/artisan view:cache || true
fi

echo "[Docker] Laravel initialization completed."

# If arguments were passed to docker run / CMD (other than default supervisord)
if [ "$1" != "supervisord" ] && [ "$#" -gt 0 ]; then
    exec "$@"
fi

exec supervisord -c /etc/supervisor/supervisord.conf
