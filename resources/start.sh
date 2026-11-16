#!/bin/bash
set -eu
mkdir -p /data/sessions /data/uploads
chown www-data:www-data /data /data/sessions /data/uploads
if [ -f /data/database.sqlite ]; then chown www-data:www-data /data/database.sqlite; fi
ln -sfn /data/uploads /app/www/uploads

# Migration and diagnostic commands run without starting the HTTP services.
if [ "${1:-}" != serve ]; then
    exec docker-php-entrypoint "$@"
fi

nginx -t
php-fpm --test
php-fpm --nodaemonize &
fpm=$!
nginx -g 'daemon off;' &
nginx=$!
# Invoked indirectly by the EXIT trap.
# shellcheck disable=SC2329
cleanup() {
    trap - EXIT
    kill -QUIT "$nginx" "$fpm" 2>/dev/null || true
    wait "$nginx" 2>/dev/null || true
    wait "$fpm" 2>/dev/null || true
}
trap 'cleanup' EXIT
trap 'exit 0' TERM INT QUIT
# Either service exiting unexpectedly must stop the other and fail the container.
if wait -n "$fpm" "$nginx"; then
    exit 1
else
    exit $?
fi
