ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-bookworm AS runtime
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx \
    && docker-php-ext-install -j2 pcntl pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
COPY tooling/nginx.conf /etc/nginx/nginx.conf
COPY tooling/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-deploy.conf
EXPOSE 80
WORKDIR /app

FROM runtime AS build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY source/composer.json source/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY frontend/ /frontend/
RUN cd /frontend && if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; fi
COPY source/ ./
RUN if [ -d /frontend/node_modules ]; then cp -a /frontend/node_modules ./; fi
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
COPY tooling/build.php /tmp/deploy-build.php
RUN php /tmp/deploy-build.php && rm -rf node_modules
COPY tooling/index.php www/index.php

FROM runtime
COPY --from=build /app /app
COPY tooling/start.sh /usr/local/bin/deploy-start
RUN chmod -R a+rX /app && chmod +x /usr/local/bin/deploy-start
ENTRYPOINT ["deploy-start"]
CMD ["serve"]
