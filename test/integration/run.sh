#!/usr/bin/env bash
# A real Docker smoke test. Fixture dependencies are installed inside Docker.
set -euo pipefail
repository=$(cd "$(dirname "$0")/../.." && pwd)
mode=${1:-sqlite}
[[ "$mode" == sqlite || "$mode" == mysql ]]
workspace=$(mktemp -d)
name="deploy-test-$(date +%s)-$$"
app="gt-$name"
port=${DEPLOY_TEST_PORT:-18989}
cleanup() {
    docker ps -aq --filter "label=phpgt.application=$app" | xargs -r docker rm -f >/dev/null
    docker network rm "$app" >/dev/null 2>&1 || true
    docker volume ls -q --filter "label=phpgt.application=$app" | xargs -r docker volume rm >/dev/null
    docker image ls "$app" --format '{{.Repository}}:{{.Tag}}' | xargs -r docker image rm >/dev/null
    rm -rf "$workspace"
}
trap cleanup EXIT
mkdir -p "$workspace/project" "$workspace/server"
cp -R "$repository/test/fixture/." "$workspace/project/"
deploy() {
    local release=$1
    local incoming="$workspace/server/incoming/$release"
    mkdir -p "$incoming"
    cp -R "$repository/resources" "$incoming/tooling"
    cp -R "$workspace/project" "$incoming/source"
    sh "$incoming/tooling/deploy.sh" "$workspace/server" "$release" "$name" "${3:-$port}" "$mode" 8.4 "${2:-/}" auto
}
check_http() {
    curl --retry 10 --retry-all-errors --retry-delay 1 -fsS "http://127.0.0.1:$port/" | grep -q 'Deployment works'
    curl -fsS "http://127.0.0.1:$port/style/site.css" | grep -q '#123456'
    curl -fsS "http://127.0.0.1:$port/greeting/?name=WebEngine" | grep -q '>WebEngine</h1>'
    curl -fsS -H 'Accept: application/json' "http://127.0.0.1:$port/status/" | grep -q '"status":"ok"'
    curl -fsSI "http://127.0.0.1:$port/style/site.css" | grep -qi 'Content-Type: text/css'
    curl -fsSI "http://127.0.0.1:$port/" | grep -qi 'Server: nginx'
    [[ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/.env")" == 404 ]]
    [[ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/config.ini/")" == 404 ]]
}
deploy first
check_http
# Tooling is available in the final image, not just in the build stage.
docker exec "$app" gt --help
docker exec "$app" composer --version
docker exec "$app" npm --version
curl -fsS "http://127.0.0.1:$port/style/setup.css" | grep -q initialised
# Cron must execute as the same unprivileged account as PHP-FPM.
for _attempt in $(seq 1 50); do
    if docker exec "$app" test -f /data/cron-probe; then break; fi
    sleep 0.2
done
[[ "$(docker exec "$app" cat /data/cron-probe)" == 33 ]]
docker exec "$app" sh -c 'echo persistent > /data/uploads/probe.txt'
# Insert application data after migration; a subsequent deployment must preserve it.
# PHP expressions must reach the container without shell interpolation.
# shellcheck disable=SC2016
db_code='require "vendor/autoload.php"; $c=GT\Config\ConfigFactory::createForProject("/app", "/app/vendor/phpgt/webengine/config.default.ini"); $driver=$c->get("database.driver"); $dsn=$driver==="sqlite" ? "sqlite:".$c->get("database.schema") : "mysql:host=".$c->get("database.host").";dbname=".$c->get("database.schema"); $db=new PDO($dsn, $c->get("database.username"), $c->get("database.password"));'
docker exec "$app" php -r "$db_code \$db->exec(\"INSERT INTO item VALUES (2, 'retained')\");"
deploy second
check_http
curl -fsS "http://127.0.0.1:$port/uploads/probe.txt" | grep -q persistent
docker exec "$app" sh -c 'printf "%s\n" "<?php echo 123456789;" > /data/uploads/probe.php'
[[ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/uploads/probe.php")" == 404 ]]
[[ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/uploads/missing.txt")" == 404 ]]
docker exec "$app" php -r "$db_code if(\$db->query('SELECT COUNT(*) FROM item')->fetchColumn() != 2) { exit(1); }"
current=$(docker inspect --format '{{.Id}}' "$app")
printf 'INVALID SQL;\n' > "$workspace/project/query/_migration/002-broken.sql"
if deploy bad-migration; then echo 'Broken migration unexpectedly succeeded' >&2; exit 1; fi
[[ "$(docker inspect --format '{{.Id}}' "$app")" == "$current" ]]
check_http
rm "$workspace/project/query/_migration/002-broken.sql"
cat > "$workspace/project/build.ini" <<'BUILD'
[page/*.html]
execute=/bin/false fail-build
BUILD
if deploy bad-build; then echo 'Broken build unexpectedly succeeded' >&2; exit 1; fi
[[ "$(docker inspect --format '{{.Id}}' "$app")" == "$current" ]]
check_http
rm "$workspace/project/build.ini"
cp "$workspace/project/composer.json" "$workspace/composer.json"
# shellcheck disable=SC2016
php -r '$path=$argv[1]; $config=json_decode(file_get_contents($path), true); $config["scripts"]["deploy-init"]="/bin/false"; file_put_contents($path,json_encode($config));' "$workspace/project/composer.json"
if deploy bad-init; then echo 'Broken init unexpectedly succeeded' >&2; exit 1; fi
[[ "$(docker inspect --format '{{.Id}}' "$app")" == "$current" ]]
check_http
cp "$workspace/composer.json" "$workspace/project/composer.json"
if deploy bad-health /missing-health-check; then echo 'Failed health check unexpectedly succeeded' >&2; exit 1; fi
[[ "$(docker inspect --format '{{.Id}}' "$app")" == "$current" ]]
check_http
# Force the new container's port binding to fail after the previous one stops.
docker run -e GT_CRON_ENABLED=0 -d --name "$app-block" --label "phpgt.application=$app" -p 127.0.0.1::80 "$app:second" >/dev/null
blocked_port=$(docker port "$app-block" 80/tcp | sed 's/.*://')
if deploy bad-port / "$blocked_port"; then echo 'Port conflict unexpectedly succeeded' >&2; exit 1; fi
[[ "$(docker inspect --format '{{.Id}}' "$app")" == "$current" ]]
check_http
docker stop --time 5 "$app" >/dev/null
[[ "$(docker inspect --format '{{.State.ExitCode}}' "$app")" == 0 ]]
docker start "$app" >/dev/null
check_http
# FPM must not accept connections on the container's network-facing address.
docker exec "$app" php -r '$socket = @fsockopen(gethostbyname(gethostname()), 9000, $errno, $error, 1); if (is_resource($socket)) { exit(1); }'
# A failed service must terminate the other process and allow Docker to restart it.
restarts=$(docker inspect --format '{{.RestartCount}}' "$app")
docker exec "$app" sh -c 'kill -TERM "$(cat /run/php-fpm.pid)"'
for _attempt in $(seq 1 30); do
    if [[ "$(docker inspect --format '{{.RestartCount}}' "$app")" -gt "$restarts" ]]; then break; fi
    sleep 0.2
done
[[ "$(docker inspect --format '{{.RestartCount}}' "$app")" -gt "$restarts" ]]
check_http
# Cron exiting must also trigger a complete container restart.
restarts=$(docker inspect --format '{{.RestartCount}}' "$app")
docker exec "$app" bash -c 'kill -TERM -- "-$(cat /run/gt-cron.pid)"'
for _attempt in $(seq 1 30); do
    if [[ "$(docker inspect --format '{{.RestartCount}}' "$app")" -gt "$restarts" ]]; then break; fi
    sleep 0.2
done
[[ "$(docker inspect --format '{{.RestartCount}}' "$app")" -gt "$restarts" ]]
check_http
printf 'Docker smoke test passed (%s).\n' "$mode"
