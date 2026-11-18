# PHP.GT deployment server

A WebEngine application for `deploy.php.gt`. It reserves anonymous deployment URLs
and accepts authenticated `.tar.gz` uploads, source initialisation and queued
Docker builds. The Nginx gateway routes reserved hostnames to active application
containers and shows a refreshing status page while a new deployment is pending.

## Run with Compose

From the parent Deploy directory, on Linux with a current Docker Engine supporting
volume subpaths and Docker Compose:

```sh
docker compose up -d
```

This builds and starts the WebEngine API under PHP-FPM, the Nginx gateway and a
separate background worker. Docker restarts them unless explicitly stopped. Only
the worker has access to the Docker socket. Neither the API nor deployed
applications receive that socket. The worker controls the host daemon, so it is
trusted infrastructure.

For a local test application, put this in its `config.dev.ini`:

```ini
[deploy]
server=http://deploy.localhost:8080
```

Then run `gt deploy`. It reserves `http://<id>.deploy.localhost:8080`, uploads,
builds and activates the application. Modern browsers and curl resolve `.localhost`
names to loopback. If moving from another local deployment API, remove the old
`deploy.token` and `deploy.token_server` values once before reserving a new URL.

Compose uses a named data volume, not the host's `server/data` directory. Existing
host reservation data is left untouched. The worker and API use the same UID for
private storage. Production config is mounted into each application using only
that application's volume subdirectory. Images, app data and MySQL volumes remain
separate from the platform's API and worker containers.

Use `docker compose logs -f worker` for worker startup errors. Individual build
output is stored privately at `data/deployment/<id>/build.log` in the shared
volume and can be read with `docker compose exec worker tail -f <path>`.

`DEPLOY_DATABASE` selects `sqlite` (the default), `mysql`, `external` or `none`.
Selecting MySQL provisions a database container with persistent storage and
generated credentials. An uploaded application's `driver=mysql` does not select
this mode automatically. For a manually hosted API, equivalent settings live in
`[docker]`: `database`, `php_version` (default `8.4`) and `health_path` (default `/`).
The API's `[server] domain`, `scheme` and `port` control reserved URLs; Compose
supplies these from its environment.

## Production DNS and HTTPS

Point both `deploy.php.gt` and `*.deploy.php.gt` at the deployment host. Provision
a certificate covering both names, with its key. Certificate issuance and DNS
changes remain operator setup; this stack does not manage DNS-provider accounts.

Put `fullchain.pem` and `privkey.pem` into a certificate directory readable by
Nginx. Use regular files: symlinks pointing outside the mounted directory are
not available inside the container. Set `DEPLOY_CERT_DIRECTORY` in a gitignored `.env` file:

```dotenv
DEPLOY_CERT_DIRECTORY=/srv/phpgt-deploy/certs
```

Then start the HTTPS overlay:

```sh
docker compose -f compose.yaml -f compose.production.yaml up -d
```

The overlay defaults to `deploy.php.gt`, terminates TLS on port 443, redirects
HTTP on port 80 and reserves `https://<id>.deploy.php.gt` URLs. Ports 80 and 443
must be available. The internal HTTP listener stays on loopback port 8080; FPM
listens only on loopback port 19000. `DEPLOY_DOMAIN`, `DEPLOY_HTTPS_PORT` and
`DEPLOY_REDIRECT_PORT` allow an alternate domain or ports. For an existing host
Nginx terminating TLS, use the base stack with `DEPLOY_DOMAIN=deploy.php.gt`,
`DEPLOY_SCHEME=https` and `DEPLOY_PUBLIC_PORT=443`, then proxy the apex and wildcard
hosts to `127.0.0.1:8080`, preserving `Host` and `X-Forwarded-Proto`.

After renewing certificate files, reload the gateway with
`docker compose exec gateway nginx -s reload`. Renewals are not automated here.
New application hostnames do not require gateway reloads or new certificates.

## API

Send `Accept: application/json` on all endpoints:

- `POST /deployment/` without authorization creates a reservation and returns
  `id`, `url`, `token`, `created_at`, `expires_at`, `status`, and `upload_url`.
- The same request with `Authorization: Bearer <token>` returns the existing
  reservation, without creating another one or extending its lifetime.
- `POST /archive/` accepts the raw archive body with `Content-Type: application/gzip`
  and the same bearer authorization. The reservation response supplies `archive/`
  as an upload URL relative to the configured service base URL.
- `POST /initialise/` uses the same bearer authorization to extract the currently
  uploaded archive and returns `status=prepared`. It rejects requests before
  upload and is idempotent for an already prepared archive.
- `POST /build/` queues the prepared source with the same bearer authorization.
- `GET /build/` returns authenticated build status: `queued`, `building`,
  `deployed` or `failed`. Failed builds include a safe error message. The CLI
  polls this endpoint for up to 30 minutes; stopping the CLI does not stop a build.

- `GET /configuration/` uses bearer authorization and returns only configured
  key names from the last successful activation. Values are never returned.
  An application without this metadata must be redeployed before inspection.
- `POST /configuration/` accepts a JSON object `{"values":{"email.api_key":"…"}}`
  with bearer authorization. Keys in `[deploy]` and `[deployment]` are rejected.
  It queues a configuration update for the active
  release and returns its ID and deployment URL.
- `GET /configuration/?job=<id>` returns authenticated update status: `queued`,
  `applying`, `applied` or `failed`, without values. The Docker worker invokes
  `gt config set --stdin` in the application's image, updates the persistent
  production INI and restarts the application. It restores the previous file if
  application health checks fail. Queued payloads are private and removed from
  completed job records. Configuration updates and builds share the deployment
  lock; redeploy before setting configuration on older runtime images.

Tokens use GT\Ulid with 10 timestamp characters and 32 random characters. Only
SHA-256 token hashes are persisted. Expiry is verified from the authenticated
token's timestamp: tokens are valid for 24 hours from issuance.
When a saved token has expired, the CLI automatically reserves a fresh URL,
replaces the saved token and continues. It announces the replacement; no manual
config edit is required. Invalid tokens still report an error.

Records and uploaded source are private, under `data/deployment/<id>/`:

```text
reservation.json
upload.lock
source-<random>.tar.gz
source-<random>/
```

The record identifies the current archive. Uploads stream into `.part` files,
with a 100 MiB size limit and an exclusive per-deployment upload lock. Empty,
incomplete and non-gzip uploads are rejected. Only a completed archive is published
in an atomically replaced record; the previous archive is then removed.
The response reports `status=uploaded` and `uploaded_bytes`.

Initialisation extracts into a private staging directory. The extraction helper
rejects absolute paths, parent traversal, links, devices and duplicate files. It
supports GNU long filenames and limits extraction to 1 GiB and 100,000 entries,
with a 20-second CPU limit, 30-second wall-clock limit per tar invocation, and
a 256 MiB process memory limit. GNU tar validates the archive format, handles
long filenames and performs extraction; the server validates its escaped inventory
first and rejects links, special files and unsafe paths. Source files keep
owner read/write access and owner execute permission only when it was present.

After successful extraction, the record's `source` field names the prepared
source directory and its status becomes `prepared`. Only then is the previous
source removed. Failed extraction removes staging files and preserves the
previous source. Upload and initialisation share the same per-deployment lock.
Existing reservation records without a source field can be initialised normally.

The build worker holds the deployment lock while Docker installs dependencies,
runs any Composer `deploy-init` hook, compiles assets, migrates the configured
database, checks a candidate container and activates it. Builds use the prepared
source directory; the HTTP process never executes uploaded source. Build output
is private in `build.log`. A failed build is reported to the CLI and the previous
active container remains available. If a worker is interrupted during a build,
restart it and rerun `gt deploy` to submit a fresh build.

A successful build writes `runtime.json` with the active container, release and
loopback HTTP port. The gateway resolves each hostname against these records
without exposing private configuration or build logs. Expiry is enforced, but automatic
deletion of expired records, images, containers and volumes is not implemented.

## Tests

From the parent Deploy directory, install its development dependencies with
`composer install` and the server dependencies with `composer install -d server`.
Run `composer test-server`, or `composer test` for both CLI and server checks.
Filesystem integration tests use isolated temporary directories and never alter
application records under `server/data`.
