#!/bin/bash
set -eu
if [ -n "${GT_DEPLOY_CONFIG_DIRECTORY:-}" ]; then
    ln -sfn "$GT_DEPLOY_CONFIG_DIRECTORY/config.production.ini" /app/config.production.ini
fi
mkdir -p /data/sessions /data/uploads /app/data /app/cache
chown www-data:www-data /data /data/sessions /data/uploads
chown -R www-data:www-data /app/data /app/cache
if [ -f /data/database.sqlite ]; then
    chown www-data:www-data /data/database.sqlite
fi
ln -sfn /data/uploads /app/www/uploads

# Migration and diagnostic commands run without starting the HTTP services.
if [ "${1:-}" != serve ]; then
    exec docker-php-entrypoint "$@"
fi

nginx -t
php-fpm --test
children=()
cron=
# Invoked indirectly by the EXIT trap.
# shellcheck disable=SC2329
cleanup() {
    trap - EXIT
    if [ -n "$cron" ]; then
        kill -TERM -- "-$cron" 2>/dev/null || true
    fi
    if [ "${#children[@]}" -gt 0 ]; then
        kill -QUIT "${children[@]}" 2>/dev/null || true
        for child in "${children[@]}"; do
            wait "$child" 2>/dev/null || true
        done
    fi
}
trap 'cleanup' EXIT
trap 'exit 0' TERM INT QUIT
php-fpm --nodaemonize &
children+=("$!")
nginx -g 'daemon off;' &
children+=("$!")
if [ "${GT_CRON_ENABLED:-1}" = 1 ] && [ -f crontab ]; then
    # Give cron and its jobs their own process group for complete shutdown.
    setsid runuser -u www-data -- gt cron --watch &
    cron=$!
    printf "%s\n" "$cron" > /run/gt-cron.pid
    children+=("$cron")
fi
# Any service exiting unexpectedly must stop the others and fail the container.
if wait -n "${children[@]}"; then
    exit 1
else
    exit $?
fi
