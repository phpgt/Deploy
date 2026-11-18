FROM php:8.4-fpm-bookworm AS api
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && docker-php-ext-install -j2 pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /opt/deploy/server
COPY server/composer.json server/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts
COPY server/ ./
COPY resources/ /opt/deploy/resources/
COPY server/docker/start.sh /usr/local/bin/deploy-server-start
RUN mkdir -p www data \
    && printf '<?php require __DIR__ . "/../vendor/phpgt/webengine/go.php";\n' > www/index.php \
    && chmod +x /usr/local/bin/deploy-server-start
ENTRYPOINT ["deploy-server-start"]
CMD ["php-fpm", "--nodaemonize"]

FROM api AS worker
COPY --from=docker:29-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker:29-cli /usr/local/libexec/docker/cli-plugins/docker-buildx /usr/local/libexec/docker/cli-plugins/docker-buildx
RUN apt-get update && apt-get install -y --no-install-recommends curl git util-linux \
    && rm -rf /var/lib/apt/lists/*
CMD ["php", "bin/build", "--watch"]
