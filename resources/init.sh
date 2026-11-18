#!/bin/sh
set -eu
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
if [ -f package.json ]; then
    if [ -f package-lock.json ]; then
        npm ci --prefer-offline --no-audit --no-fund
    else
        npm install --prefer-offline --no-audit --no-fund
    fi
fi
# Application-specific setup uses Composer's existing script mechanism.
# shellcheck disable=SC2016
if php -r '$config = json_decode(file_get_contents("composer.json"), true, flags: JSON_THROW_ON_ERROR); exit(isset($config["scripts"]["deploy-init"]) ? 0 : 1);'; then
    composer run-script --no-interaction deploy-init
fi
# The published gt build currently returns zero for some failed builds.
# Use its build engine directly until that exit-status contract is fixed.
php /tmp/build.php
