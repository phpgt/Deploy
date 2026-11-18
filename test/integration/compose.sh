#!/usr/bin/env bash
# Real Compose platform: isolated applications, hostname routing and wildcard TLS.
set -euo pipefail
repository=$(cd "$(dirname "$0")/../.." && pwd)
cd "$repository"
export DEPLOY_DATABASE=${1:-sqlite}
[[ "$DEPLOY_DATABASE" == sqlite || "$DEPLOY_DATABASE" == mysql ]]
workspace=$(mktemp -d)
export COMPOSE_PROJECT_NAME
COMPOSE_PROJECT_NAME="gt-compose-test-$(date +%s)-$$"
export DEPLOY_DOMAIN=deploy.localhost
export NO_PROXY=localhost,127.0.0.1,.localhost
export no_proxy="$NO_PROXY"
# shellcheck disable=SC2016
mapfile -t ports < <(php -r '$s=[]; for($i=0;$i<4;$i++) {$s[]=stream_socket_server("tcp://127.0.0.1:0"); echo explode(":",stream_socket_get_name($s[$i],false))[1],"\n";}')
export DEPLOY_HTTP_PORT=${ports[0]}
export DEPLOY_PUBLIC_PORT=$DEPLOY_HTTP_PORT
export DEPLOY_FPM_PORT=${ports[1]}
export DEPLOY_HTTPS_PORT=${ports[2]}
export DEPLOY_REDIRECT_PORT=${ports[3]}
export DEPLOY_CERT_DIRECTORY="$workspace/certs"
cli_pid=
cleanup() {
    if [[ -n "$cli_pid" ]]; then kill "$cli_pid" 2>/dev/null || true; wait "$cli_pid" 2>/dev/null || true; fi
    docker compose stop worker >/dev/null 2>&1 || true
    for app in $(grep -hoE 'http://[a-f0-9]{16}\.' "$workspace/"*.out 2>/dev/null | cut -d/ -f3 | tr -d '.' | sort -u); do
        app="gt-$app"
        docker ps -aq --filter "label=phpgt.application=$app" | xargs -r docker rm -f >/dev/null
        docker network rm "$app" >/dev/null 2>&1 || true
        docker volume ls -q --filter "label=phpgt.application=$app" | xargs -r docker volume rm >/dev/null
        docker image ls "$app" --format '{{.Repository}}:{{.Tag}}' | xargs -r docker image rm >/dev/null
    done
    docker compose -f compose.yaml -f compose.production.yaml down --volumes >/dev/null 2>&1 || true
    docker image rm "$COMPOSE_PROJECT_NAME-api" "$COMPOSE_PROJECT_NAME-worker" >/dev/null 2>&1 || true
    for output in "$workspace/"*.out; do
        [[ -f "$output" ]] || continue
        sed -n 's/^Archive: //p' "$output" | while IFS= read -r archive; do rm -f -- "$archive"; done
    done
    rm -rf "$workspace"
}
trap cleanup EXIT
# On unexpected assertion failures show the isolated fixture's CLI output.
trap 'echo "Compose assertion failed at line $LINENO" >&2; if [[ -f "$workspace/first.out" ]]; then cat "$workspace/first.out"; fi' ERR
mkdir -p "$DEPLOY_CERT_DIRECTORY"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=deploy.localhost' \
    -addext 'subjectAltName=DNS:deploy.localhost,DNS:*.deploy.localhost' \
    -keyout "$DEPLOY_CERT_DIRECTORY/privkey.pem" -out "$DEPLOY_CERT_DIRECTORY/fullchain.pem" >/dev/null 2>&1
docker compose up -d --build --wait
for project in one two; do
    mkdir "$workspace/$project"
    cp -R test/fixture/. "$workspace/$project/"
    printf '[deploy]\nserver=http://deploy.localhost:%s\n' "$DEPLOY_HTTP_PORT" > "$workspace/$project/config.dev.ini"
    cat >> "$workspace/$project/config.dev.ini" <<'INI'
[mail]
secret=compose-private-secret
host=smtp.example.test
[session]
cookie_secure=false
INI
    cat > "$workspace/$project/config.extra.ini" <<'INI'
[mail]
numeric="000123"
multiline="line1
line2"
quoted="a\"b;= $plain \${MISSING_ENV}"
INI
done
printf '<h1>Second application</h1>\n' > "$workspace/two/page/index.html"
run_cli() {
    (cd "$workspace/$1" && php "$repository/bin/deploy" "${@:3}") > "$workspace/$2.out" 2>&1
}
run_cli one first &
cli_pid=$!
for _attempt in $(seq 1 100); do
    if grep -qE 'http://[a-f0-9]{16}\.deploy.localhost' "$workspace/first.out"; then break; fi
    sleep 0.1
done
url=$(grep -oE 'http://[a-f0-9]{16}\.deploy.localhost:[0-9]+' "$workspace/first.out" | head -1)
[[ "$(curl --noproxy '*' -s -o /dev/null -w '%{http_code}' "$url/")" == 503 ]]
if ! wait "$cli_pid"; then
    cat "$workspace/first.out"
    docker compose exec -T worker sh -c 'tail -n 70 data/deployment/*/build.log'
    docker compose logs --tail 80 worker api gateway
    exit 1
fi
cli_pid=
curl --noproxy '*' -fsS "$url/" | grep -q 'Deployment works'
# An independent build must reinstall offline using the shared caches, without a deployment ID.
application=${url#http://}
application="gt-${application%%.*}"
image=$(docker compose exec -T worker docker inspect --format '{{.Config.Image}}' "$application")
docker compose exec -T worker env DOCKER_BUILDKIT=1 docker build --network=none --progress=plain \
    --build-arg "APPLICATION_IMAGE=$image" \
    -t "$application:cache-check" - <<'DOCKERFILE'
# syntax=docker/dockerfile:1
ARG APPLICATION_IMAGE=php:8.4-fpm-bookworm
FROM ${APPLICATION_IMAGE}
RUN test ! -e /var/cache/composer/files && test ! -e /var/cache/npm/_cacache
RUN --mount=type=cache,id=gt-deploy-composer,target=/var/cache/composer,sharing=locked \
    --mount=type=cache,id=gt-deploy-npm,target=/var/cache/npm,sharing=locked \
    rm -rf vendor node_modules && \
    COMPOSER_CACHE_DIR=/var/cache/composer composer install --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist && \
    npm ci --cache=/var/cache/npm --offline --ignore-scripts --no-audit --no-fund
DOCKERFILE
grep -q "gt deploy config-set 'mail.secret'" "$workspace/first.out"
if grep -q "gt deploy config-set 'session.cookie_secure'" "$workspace/first.out"; then
    echo 'Configured false value was reported as unset' >&2
    exit 1
fi
if grep -q 'compose-private-secret' "$workspace/first.out"; then
    echo 'Configuration value appeared in CLI output' >&2
    exit 1
fi
[[ "$(curl -H "Accept: application/json" --noproxy '*' -s -o /dev/null -w '%{http_code}' "http://deploy.localhost:$DEPLOY_HTTP_PORT/configuration/")" == 401 ]]
if ! run_cli one config-key config-set mail.secret; then
    cat "$workspace/config-key.out"
    docker compose exec -T worker sh -c 'tail -n 30 data/deployment/*/configuration.log'; docker compose logs --tail 15 worker
    exit 1
fi
curl --noproxy '*' -fsS -H 'Accept: application/json' "$url/config-status/" | grep -q '"secret_matches":true'
if ! run_cli one config-section config-set mail; then cat "$workspace/config-section.out"; docker compose exec -T worker sh -c 'tail -n 30 data/deployment/*/configuration.log'; docker compose logs --tail 15 worker; exit 1; fi
# shellcheck disable=SC2016
curl --noproxy '*' -fsS -H 'Accept: application/json' "$url/config-status/" | php -r '$r=json_decode(stream_get_contents(STDIN),true); foreach($r as $key=>$value){if($value!==true){fwrite(STDERR,"Configuration mismatch: $key\n"); exit(1);}}'
if ! run_cli one config-all config-set '*'; then cat "$workspace/config-all.out"; exit 1; fi
if grep -q 'compose-private-secret' "$workspace/config-"*.out; then echo 'Secret value appeared in config-set output' >&2; exit 1; fi
# Completed jobs must not retain their secret payloads.
# shellcheck disable=SC2016
docker compose exec -T worker php -r 'foreach(glob("data/deployment/*/configuration/*.json") as $p){$j=json_decode(file_get_contents($p),true); if(isset($j["values"]) || $j["status"]!=="applied"){exit(1);}}'
if ! run_cli two second; then cat "$workspace/second.out"; docker compose logs --tail 80 worker; exit 1; fi
url_two=$(grep -oE 'http://[a-f0-9]{16}\.deploy.localhost:[0-9]+' "$workspace/second.out" | head -1)
[[ "$url_two" != "$url" ]]
curl --noproxy '*' -fsS "$url_two/" | grep -q 'Second application'
curl --noproxy '*' -fsS "$url/" | grep -q 'Deployment works'
[[ "$(curl --noproxy '*' -s -o /dev/null -w '%{http_code}' "http://0000000000000000.deploy.localhost:$DEPLOY_HTTP_PORT/")" == 404 ]]
printf '<h1>Updated first application</h1>\n' > "$workspace/one/page/index.html"
if ! run_cli one update; then cat "$workspace/update.out"; exit 1; fi
curl --noproxy '*' -fsS "$url/" | grep -q 'Updated first application'
curl --noproxy '*' -fsS -H 'Accept: application/json' "$url/config-status/" | grep -q '"secret_matches":true'
curl --noproxy '*' -fsS "$url_two/" | grep -q 'Second application'
# A failed build keeps the same hostname routed to the previous active release.
# shellcheck disable=SC2016
php -r '$p=$argv[1]; $c=json_decode(file_get_contents($p),true); $c["scripts"]["deploy-init"]="/bin/false"; file_put_contents($p,json_encode($c));' "$workspace/one/composer.json"
if run_cli one failed; then echo 'Failed build unexpectedly succeeded' >&2; exit 1; fi
grep -q 'Deployment build failed' "$workspace/failed.out"
curl --noproxy '*' -fsS "$url/" | grep -q 'Updated first application'
# Restarting the controller services must preserve both applications and routes.
docker compose restart worker gateway
curl --noproxy '*' --retry 10 --retry-all-errors --retry-delay 1 -fsS "$url_two/" | grep -q 'Second application'
# The production overlay terminates TLS with a supplied wildcard certificate.
docker compose -f compose.yaml -f compose.production.yaml up -d --wait
https_url=${url_two/http:/https:}
https_url=${https_url/:$DEPLOY_HTTP_PORT/:$DEPLOY_HTTPS_PORT}
curl --noproxy '*' --cacert "$DEPLOY_CERT_DIRECTORY/fullchain.pem" -fsS "$https_url/" | grep -q 'Second application'
curl --noproxy '*' --cacert "$DEPLOY_CERT_DIRECTORY/fullchain.pem" -fsS -H 'Accept: application/json' "$https_url/status/" | grep -q '"scheme":"https"'
# shellcheck disable=SC2016
curl --noproxy '*' --cacert "$DEPLOY_CERT_DIRECTORY/fullchain.pem" -fsS -X POST -H 'Accept: application/json' \
    "https://deploy.localhost:$DEPLOY_HTTPS_PORT/deployment/" | php -r '$r=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR); exit(str_starts_with($r["url"], "https://") ? 0 : 1);'
printf 'Compose hostname routing and TLS smoke test passed.\n'
