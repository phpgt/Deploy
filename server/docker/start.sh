#!/bin/sh
set -eu
mkdir -p data
chown www-data:www-data data
php docker/configure.php
if [ -S /var/run/docker.sock ]; then
    export DOCKER_CONFIG=/tmp/deploy-docker
    mkdir -p "$DOCKER_CONFIG"
    chown www-data:www-data "$DOCKER_CONFIG"
    socket_group=$(stat -c '%g' /var/run/docker.sock)
    if ! getent group "$socket_group" >/dev/null; then
        groupadd -g "$socket_group" docker-host
    fi
    usermod -aG "$socket_group" www-data
    exec runuser -u www-data -- "$@"
fi
exec docker-php-entrypoint "$@"
