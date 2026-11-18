# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-bookworm AS runtime
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx git unzip \
    && docker-php-ext-install -j2 pcntl pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY tooling/gt/composer.json tooling/gt/composer.lock /opt/phpgt/
RUN --mount=type=cache,id=gt-deploy-composer,target=/var/cache/composer,sharing=locked \
    COMPOSER_CACHE_DIR=/var/cache/composer composer install --working-dir=/opt/phpgt --no-dev --no-interaction --prefer-dist --no-progress \
    && chmod -R a+rX /opt/phpgt
COPY tooling/gt-command /usr/local/bin/gt
COPY tooling/config-set.php /usr/local/lib/phpgt/config-set.php
RUN chmod 0755 /usr/local/bin/gt && chmod 0644 /usr/local/lib/phpgt/config-set.php
COPY tooling/nginx.conf /etc/nginx/nginx.conf
COPY tooling/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-deploy.conf
EXPOSE 80
WORKDIR /app

FROM runtime AS build
COPY source/ ./
COPY tooling/init.sh tooling/build.php /tmp/
RUN --mount=type=cache,id=gt-deploy-composer,target=/var/cache/composer,sharing=locked \
    --mount=type=cache,id=gt-deploy-npm,target=/var/cache/npm,sharing=locked \
    COMPOSER_CACHE_DIR=/var/cache/composer npm_config_cache=/var/cache/npm \
    sh /tmp/init.sh && rm /tmp/init.sh /tmp/build.php
COPY tooling/index.php www/index.php

FROM runtime
LABEL phpgt.configuration=1
COPY --from=build /app /app
COPY tooling/start.sh /usr/local/bin/deploy-start
COPY tooling/configuration.php /usr/local/lib/phpgt/configuration.php
RUN chmod -R a+rX /app && chmod +x /usr/local/bin/deploy-start
ENTRYPOINT ["deploy-start"]
CMD ["serve"]
