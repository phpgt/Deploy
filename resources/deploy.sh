#!/bin/sh
# Build and activate a prepared source directory inside Docker.
set -eu
umask 077
root=$1
release=$2
id=$3
app="gt-$id"
port=$4
database=$5
php_version=$6
health_path=$7
migrate=$8
for tool in docker curl flock php; do command -v "$tool" >/dev/null || { echo "Missing server prerequisite: $tool" >&2; exit 1; }; done
docker info >/dev/null
cd "$root"
root=$(pwd)
# Lock covers extraction, building, migrations and activation.
exec 9>"$root/deploy.lock"
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 1; }
incoming="$root/incoming/$release"
stage="$root/releases/$release"
if [ -f "$root/application" ]; then
    test "$(cat "$root/application")" = "$app" || { echo 'Deployment path belongs to another application.' >&2; exit 1; }
else
    printf '%s\n' "$app" > "$root/application"
fi
mkdir -p "$stage/source" "$root/shared"
chmod 755 "$root/shared"
image="$app:$release"
candidate="$app-check-$release"
previous="$app-previous-$release"
switched=false
old_stopped=false
new_started=false
cleanup() {
    code=$?
    trap - EXIT HUP INT TERM
    docker rm -f "$candidate" >/dev/null 2>&1 || true
    if [ "$switched" = false ]; then
        if [ "$new_started" = true ]; then docker rm -f "$app" >/dev/null 2>&1 || true; fi
        if [ "$old_stopped" = true ]; then
            if docker container inspect "$previous" >/dev/null 2>&1; then docker rename "$previous" "$app"; fi
            docker start "$app" >/dev/null || echo 'Failed to restart previous release; inspect Docker logs.' >&2
        fi
    fi
    rm -rf "$incoming"
    exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' HUP TERM
cp -a "$incoming/source/." "$stage/source/"
cp -R "$incoming/tooling" "$stage/tooling"
# Never send local runtime overrides to the image, even if an archive was made externally.
rm -f "$stage/source/config.dev.ini" "$stage/source/config.deploy.ini" "$stage/source/config.production.ini"
# A persistent marker prevents accidental database engine changes on later deployments.
if [ -f "$root/shared/database-mode" ]; then
    test "$(cat "$root/shared/database-mode")" = "$database" || { echo 'Database mode differs from the provisioned mode.' >&2; exit 1; }
else
    printf '%s\n' "$database" > "$root/shared/database-mode"
fi
owned() {
    test "$(docker container inspect --format '{{ index .Config.Labels "phpgt.application" }}' "$1")" = "$app"
}
if docker container inspect "$app" >/dev/null 2>&1; then owned "$app" || { echo 'Application container name is already in use.' >&2; exit 1; }; fi
if docker network inspect "$app" >/dev/null 2>&1; then
    test "$(docker network inspect --format '{{ index .Labels "phpgt.application" }}' "$app")" = "$app" || exit 1
else
    docker network create --label "phpgt.application=$app" "$app" >/dev/null
fi
# Docker manages the persistent data directory; it is never part of a release cleanup.
docker volume inspect "$app-data" >/dev/null 2>&1 || docker volume create --label "phpgt.application=$app" "$app-data" >/dev/null
test "$(docker volume inspect --format '{{ index .Labels "phpgt.application" }}' "$app-data")" = "$app" || exit 1
mkdir -p "$root/shared/config"
# Only this application receives the directory mount; PHP-FPM must be able to traverse it.
chmod 0755 "$root/shared/config"
config="$root/shared/config/config.production.ini"
if [ -f "$root/shared/config.production.ini" ] && [ ! -f "$config" ]; then
    mv "$root/shared/config.production.ini" "$config"
fi
if [ "$database" = external ] && [ ! -f "$config" ]; then
    echo "Create $config with production [database] settings, then deploy again." >&2
    exit 1
fi
if [ "$database" = mysql ]; then
    command -v openssl >/dev/null
    if [ ! -f "$root/shared/mysql.env" ]; then
        password=$(openssl rand -hex 24)
        root_password=$(openssl rand -hex 24)
        printf 'MYSQL_DATABASE=app\nMYSQL_USER=app\nMYSQL_PASSWORD=%s\nMYSQL_ROOT_PASSWORD=%s\n' "$password" "$root_password" > "$root/shared/mysql.env"
        printf '[database]\ndriver=mysql\nhost=database\nport=3306\nschema=app\nusername=app\npassword="%s"\n' "$password" > "$config"
    fi
    test -f "$config" || { echo 'MySQL production configuration is missing; restore it before deploying.' >&2; exit 1; }
    docker volume inspect "$app-mysql" >/dev/null 2>&1 || docker volume create --label "phpgt.application=$app" "$app-mysql" >/dev/null
    test "$(docker volume inspect --format '{{ index .Labels "phpgt.application" }}' "$app-mysql")" = "$app" || exit 1
    if docker container inspect "$app-db" >/dev/null 2>&1; then
        owned "$app-db" || exit 1
        docker start "$app-db" >/dev/null
    else
        docker run -d --name "$app-db" --label "phpgt.application=$app" --restart unless-stopped \
            --network "$app" --network-alias database --env-file "$root/shared/mysql.env" \
            -v "$app-mysql:/var/lib/mysql" mysql:8.4 >/dev/null
    fi
    ready=false
    for _attempt in $(seq 1 60); do
        if docker exec "$app-db" sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u "$MYSQL_USER" "$MYSQL_DATABASE" -e "SELECT 1"' >/dev/null 2>&1; then ready=true; break; fi
        sleep 2
    done
    test "$ready" = true || { echo 'MySQL did not become ready.' >&2; exit 1; }
fi
if [ ! -f "$config" ]; then
    if [ "$database" = sqlite ]; then
        printf '[database]\ndriver=sqlite\nschema=/data/database.sqlite\n' > "$config"
    else
        printf '; Production settings\n' > "$config"
    fi
fi
# Generate runtime defaults separately; production overrides still take precedence.
printf '[app]\nproduction=true\n[logger]\ndebug_to_javascript=false\n[session]\npath=/data/sessions\n' > "$stage/source/config.deploy.ini"
# The HTTP-only initial release needs cookie_secure=false only when explicitly configured by the user.
chmod 644 "$config"
echo 'Building application image...'
DOCKER_BUILDKIT=1 docker build --build-arg "PHP_VERSION=$php_version" -f "$stage/tooling/Dockerfile" -t "$image" "$stage"
run_app() {
    if [ -n "${DEPLOY_DATA_VOLUME:-}" ]; then
        docker run --label "phpgt.application=$app" --network "$app" \
            -v "$app-data:/data" \
            -e GT_DEPLOY_CONFIG_DIRECTORY=/deployment-config \
            --mount "type=volume,src=$DEPLOY_DATA_VOLUME,dst=/deployment-config,volume-subpath=deployment/$id/shared/config,readonly" "$@"
    else
        docker run --label "phpgt.application=$app" --network "$app" \
            -v "$app-data:/data" -v "$config:/app/config.production.ini:ro" "$@"
    fi
}
if [ "$migrate" = auto ]; then
    if [ -d "$stage/source/query/_migration" ] && [ "$database" != none ]; then migrate=true; else migrate=false; fi
fi
if [ "$migrate" = true ] || [ "$migrate" = 1 ]; then
    test "$database" != none || { echo 'Migrations require a database.' >&2; exit 1; }
    echo 'Running production migrations...'
    run_app --rm "$image" gt migrate
fi
wait_http() {
    for _attempt in $(seq 1 30); do
        status=$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 2 "http://127.0.0.1:$1$health_path") || status=000
        case "$status" in 2??) return 0 ;; esac
        sleep 1
    done
    return 1
}
echo 'Checking candidate release...'
run_app -e GT_CRON_ENABLED=0 -d --name "$candidate" -p 127.0.0.1::80 "$image" >/dev/null
candidate_port=$(docker port "$candidate" 80/tcp | sed 's/.*://')
wait_http "$candidate_port" || { docker logs "$candidate"; echo 'Candidate failed its health check.' >&2; exit 1; }
docker rm -f "$candidate" >/dev/null
if docker container inspect "$app" >/dev/null 2>&1; then
    old_stopped=true
    docker stop "$app" >/dev/null
    docker rename "$app" "$previous"
fi
# There is a short interruption while the fixed port changes containers.
new_started=true
if [ "$port" = auto ]; then
    binding=127.0.0.1::80
else
    binding="127.0.0.1:$port:80"
fi
run_app -d --name "$app" --restart unless-stopped -p "$binding" "$image" >/dev/null
port=$(docker port "$app" 80/tcp | sed 's/.*://')
wait_http "$port" || { docker logs "$app"; echo 'New release failed; restoring previous container.' >&2; exit 1; }
run_app --rm "$image" php /usr/local/lib/phpgt/configuration.php > "$root/configuration-keys.json"
php "$stage/tooling/runtime-state.php" "$root/configuration-keys.json" "$port" "$app" "$release" > "$root/runtime.json.part"
rm "$root/configuration-keys.json"
mv "$root/runtime.json.part" "$root/runtime.json"
ln -sfn "releases/$release" "$root/current"
switched=true
if [ "$old_stopped" = true ]; then docker rm "$previous" >/dev/null; fi
echo "Deployed $release on HTTP port $port."
