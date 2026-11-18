#!/usr/bin/env bash
# Real gt deploy -> HTTP upload -> queue -> Docker worker -> live application.
set -euo pipefail
repository=$(cd "$(dirname "$0")/../.." && pwd)
workspace=$(mktemp -d)
server_pid=
worker_pid=
cleanup() {
    if [[ -n "$worker_pid" ]]; then kill "$worker_pid" 2>/dev/null || true; wait "$worker_pid" 2>/dev/null || true; fi
    if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
    for record in "$workspace"/server/data/deployment/*/reservation.json; do
        [[ -f "$record" ]] || continue
        local app
        app="gt-$(basename "$(dirname "$record")")"
        docker ps -aq --filter "label=phpgt.application=$app" | xargs -r docker rm -f >/dev/null
        docker network rm "$app" >/dev/null 2>&1 || true
        docker volume ls -q --filter "label=phpgt.application=$app" | xargs -r docker volume rm >/dev/null
        docker image ls "$app" --format '{{.Repository}}:{{.Tag}}' | xargs -r docker image rm >/dev/null
    done
    # Archive paths come from the CLI, not from user input.
    for output in "$workspace"/*.out; do
        [[ -f "$output" ]] || continue
        sed -n 's/^Archive: //p' "$output" | while IFS= read -r archive; do rm -f -- "$archive"; done
    done
    rm -rf "$workspace"
}
trap cleanup EXIT
mkdir -p "$workspace/server/api" "$workspace/server/bin" "$workspace/server/www" "$workspace/project"
cp "$repository/server/api/"*.php "$workspace/server/api/"
cp "$repository/server/bin/build" "$workspace/server/bin/"
cp "$repository/server/config.ini" "$workspace/server/"
ln -s "$repository/server/vendor" "$workspace/server/vendor"
printf '<?php require __DIR__ . "/../vendor/phpgt/webengine/go.php";\n' > "$workspace/server/www/index.php"
cp -R "$repository/test/fixture/." "$workspace/project/"
# shellcheck disable=SC2016
address=$(php -r '$socket=stream_socket_server("tcp://127.0.0.1:0"); echo stream_socket_get_name($socket, false);')
printf '[deploy]\nserver=http://%s\n' "$address" > "$workspace/project/config.dev.ini"
(
    cd "$workspace/server"
    exec php -S "$address" -t www vendor/phpgt/webengine/go.php
) > "$workspace/http.log" 2>&1 &
server_pid=$!
php "$workspace/server/bin/build" --watch > "$workspace/worker.log" 2>&1 &
worker_pid=$!
for _attempt in $(seq 1 50); do
    if curl -s "http://$address/" >/dev/null; then break; fi
    sleep 0.1
done
run_cli() {
    (cd "$workspace/project" && php "$repository/bin/deploy") > "$workspace/$1.out" 2>&1
}
if ! run_cli first; then cat "$workspace/first.out" "$workspace/worker.log"; exit 1; fi
record=$(find "$workspace/server/data/deployment" -name reservation.json)
root=$(dirname "$record")
app="gt-$(basename "$root")"
# shellcheck disable=SC2016
port=$(php -r '$r=json_decode(file_get_contents($argv[1]), true); echo $r["port"];' "$root/runtime.json")
curl -fsS "http://127.0.0.1:$port/" | grep -q 'Deployment works'
grep -q 'Deployed:' "$workspace/first.out"
printf '<h1>Second deployment</h1>\n' > "$workspace/project/page/second.html"
if ! run_cli second; then cat "$workspace/second.out"; exit 1; fi
# shellcheck disable=SC2016
port=$(php -r '$r=json_decode(file_get_contents($argv[1]), true); echo $r["port"];' "$root/runtime.json")
curl -fsS "http://127.0.0.1:$port/second/" | grep -q 'Second deployment'
current=$(docker inspect --format '{{.Id}}' "$app")
# A failed build must reach the CLI without replacing the active container.
# shellcheck disable=SC2016
php -r '$path=$argv[1]; $config=json_decode(file_get_contents($path),true); $config["scripts"]["deploy-init"]="/bin/false"; file_put_contents($path,json_encode($config));' "$workspace/project/composer.json"
if run_cli failed; then echo 'Failed build unexpectedly succeeded' >&2; exit 1; fi
grep -q 'Deployment build failed' "$workspace/failed.out"
[[ "$(docker inspect --format '{{.Id}}' "$app")" == "$current" ]]
curl -fsS "http://127.0.0.1:$port/second/" | grep -q 'Second deployment'
printf 'HTTP upload and Docker worker smoke test passed.\n'
