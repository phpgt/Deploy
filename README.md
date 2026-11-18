Deployment tools for PHP projects.
==================================

PHP.GT/Deploy is the foundation for deployment automation of WebEngine projects. It provides a standalone CLI and a command class intended for integration with `gt deploy` in PHP.GT/GtCommand.

***

<a href="https://github.com/PhpGt/Deploy/actions" target="_blank">
	<img src="https://badge.status.php.gt/deploy-build.svg" alt="Build status" />
</a>
<a href="https://app.codacy.com/gh/PhpGt/Deploy" target="_blank">
	<img src="https://badge.status.php.gt/deploy-quality.svg" alt="Code quality" />
</a>
<a href="https://app.codecov.io/gh/PhpGt/Deploy" target="_blank">
	<img src="https://badge.status.php.gt/deploy-coverage.svg" alt="Code coverage" />
</a>

## Usage

Run `gt deploy` from a WebEngine project root. PHP 8.3+ and GNU `tar` are required
locally. No account, SSH access or local Docker installation is needed.

The command reserves a public URL and prints it before packaging. It saves the
private deployment token in `config.dev.ini` using GT\Config, creates a `.tar.gz`
in the system temporary directory, then uploads it with a progress bar. Later
runs authenticate with the saved token and update the same deployment.

After uploading, the CLI calls `POST /initialise/` to extract the source, then
`POST /build/` to queue a Docker build. It polls authenticated build status until
activation succeeds or fails. Build output stays in the server deployment’s `build.log`.
The gateway serves the application at its reserved hostname after activation.
While a new application is being prepared, that hostname shows a refreshing status page.
Local archives remain available at the printed path for inspection.

## Configuration and tokens

WebEngine defines `[deploy] server=deploy.php.gt` in `config.default.ini`.
To use a local server, add this to the project's `config.dev.ini` or `config.ini`:

```ini
[deploy]
server=http://deploy.localhost:8080
```

HTTPS is assumed when no scheme is supplied. The value is the server base URL,
not an API endpoint. Project config overrides are loaded normally.

The CLI writes `deploy.token` and `deploy.token_server` into `config.dev.ini`,
preserving existing settings and restricting file permissions to the owner.
The token is saved before packaging, so interrupted deployments can be retried.
Changing the server requires removing both saved token values before reserving
another deployment. Invalid tokens report an error. If an anonymous deployment
has expired, `gt deploy` explains that it is reserving a new URL, saves the fresh
token automatically and continues without requiring config edits.

Tokens are generated with GT\Ulid and contain a timestamp plus 160 random bits.
They expire 24 hours after reservation; updating a deployment does not renew them.
The server stores a token hash, not the bearer token itself.

The server uses GNU tar with gzip, timeout and prlimit for bounded extraction. Extracted files live in
`data/deployment/<id>/source-<archive-id>/` in the server's data storage
(the Compose named volume, or `server/data` for manual hosting); the record
`reservation.json` identifies the current source directory. An invalid archive
does not replace the previous source.

Ignore `config.*.ini` in Git. The CLI warns if `config.dev.ini` is not effectively
ignored, including when it is already tracked. It does not edit your project's
`.gitignore` automatically. Neither terminal output nor archives include the saved token.

## Deployment configuration

After a successful deployment, the CLI lists missing or blank keys found in local
`config.*.ini` overrides, showing commands without their values. Run each command
individually, or select a whole section or every local override key:

```sh
gt deploy config-set email.api_key
gt deploy config-set email
gt deploy config-set '*'
```

Values come from the local override files; they are never command arguments or
printed in the terminal. Quote `'*'` to prevent shell expansion. Standard override
files follow GT\Config precedence; custom override files are read first, in
alphabetical order. Only scalar values are supported. `[deploy]` and
`app.production` are excluded. Explicit `false` and `0` count as configured.

The command authenticates using the saved deployment token and sends the selected
values to the deployment server. The Docker worker runs `gt config set --stdin`
to merge them into the persistent `config.production.ini`, then restarts the app.
If applying or checking the configuration fails, it restores the previous file.
Queued values are removed from the job record after processing. Use HTTPS outside
local development.

Existing applications need one successful `gt deploy` to install the updated
runtime before using `config-set`. Settings persist across subsequent deployments.
An expired deployment must first be renewed with `gt deploy`.

The authenticated `GET /configuration/` endpoint returns configured key names,
never values. An inspection failure produces a warning without changing a
successful deployment's exit code.

## Source exclusions

The archive excludes root `cache`, `test`, `vendor` and `node_modules` directories,
Git and IDE metadata, `.env` files, `config.*.ini` overrides, common local database
files, private keys and `.ssh`. Add project-specific tar patterns to `.deployignore`.
`config.ini` is included: keep local credentials in excluded overrides.

In a Git working tree, only tracked files within the project directory are
packaged, using their current contents. Untracked files and tracked files deleted
locally are omitted. Outside Git, all files are eligible. The exclusions above
apply in both cases. Symlinks in the deployment source cause packaging to fail;
they are never followed or uploaded. Untracked generated links in Git projects
are omitted along with other untracked files.

## Development

```sh
composer install
composer install --working-dir=server
composer test
```

Tests use isolated directories and a local WebEngine server. They do not modify
your application's configuration or the development server's reservation data.

## Dependency caching

Application builds use Docker BuildKit cache mounts for Composer downloads and
npm's package cache. Later deployments reuse downloaded packages, including when
source changes require another install. npm prefers cached packages but can fetch
missing ones. Install scripts, `deploy-init` and production asset compilation
still run normally.

All applications share one Composer download cache and one npm package cache.
The `gt` toolchain also uses the shared Composer cache. Cache mounts are locked
while in use to coordinate concurrent builds. Cache contents are
not included in the deployed image. They live in the server's Docker builder,
survive worker/container restarts, and may be reclaimed by Docker build-cache
pruning; a missing cache only makes the next build colder.

Application builds require BuildKit and the Docker Buildx plugin. The Compose
worker already includes Buildx; manual workers must provide it too. Keep the
same builder between builds to reuse its cache.

## Docker runtime

`resources/Dockerfile` builds applications using Nginx and PHP-FPM in one
container. Nginx serves `www/` assets and forwards only the WebEngine front
controller to PHP-FPM over loopback. The image includes Composer, Node.js, npm and
`gt` (1.4.0), with the gt dependency versions locked in `resources/gt/composer.lock`.
The tools remain available from `/app` in the running container.

Image creation runs `composer install --no-dev`, then `npm ci` when a package lock
exists or `npm install` otherwise. Both package managers run their normal install
scripts. Optional extra setup belongs in Composer's `deploy-init` script, which
runs after dependencies are installed and before assets are built. For example:

```json
{"scripts": {"deploy-init": ["php initialise.php"]}}
```

Asset compilation currently uses the same engine as `gt build`, with explicit
failure checks: published `gt build` can return zero after a failed build. The
strict runner is retained until that upstream behaviour is fixed. `gt build`
is also available for manual use in the container.

The deployment script runs `gt migrate` against the production database before
activation, when migrations are enabled. Migration and diagnostic commands bypass
the HTTP services. A project with a `crontab` runs `gt cron --watch` as `www-data`
in the live container. Nginx, PHP-FPM and cron are supervised together: shutdown
stops all of them, and an unexpected exit fails the container for Docker to
restart. Health-check candidates disable cron with `GT_CRON_ENABLED=0`, preventing
duplicate scheduled jobs while the previous release is still serving.

Database provisioning currently requires an explicit mode passed to
`resources/deploy.sh`: `mysql`, `sqlite`, `external` or `none`. The script does
not infer this mode from the application's `[database]` configuration. Selecting
`mysql` starts a separate MySQL container with persistent storage and generated
credentials, supplied to the application through `config.production.ini`. Merely
setting `driver=mysql` in the application's config does not provision a database
yet. Automatic selection from application configuration remains to be implemented.

## Run the deployment service

From this directory, on Linux with Docker Engine and Compose installed:

```sh
docker compose up -d
```

This starts Nginx, the WebEngine PHP-FPM API and the background build worker, with
restart policies. No `gt serve` or separate watcher terminal is required. Configure
a test application's `[deploy] server` as `http://deploy.localhost:8080`. Reserved
URLs use `http://<id>.deploy.localhost:8080` and resolve to loopback with modern
browsers and curl. The HTTP port can be changed with `DEPLOY_HTTP_PORT` and
`DEPLOY_PUBLIC_PORT`; `DEPLOY_FPM_PORT` selects the internal loopback FPM port.

The worker alone receives the Docker socket. The API and worker share a named
data volume; applications receive only their own production config subdirectory
and persistent application data. This requires Docker's volume-subpath support.
The stack uses Linux host networking so the gateway and worker can reach the
applications' loopback-only ports. Application containers keep separate networks.

Nginx resolves deployment hostnames through the API's private routing subrequest.
New applications, updates and rollbacks take effect without reloading Nginx.
Unknown and expired hostnames return 404. An existing active application remains
routed while an update builds or fails. The named volume preserves reservations,
build state and routes across stack restarts; existing `server/data` files are
not imported automatically. Application containers outlive the Compose services.

For production DNS and wildcard HTTPS setup, see [server/README.md](server/README.md).
Database provisioning remains explicitly configured with `DEPLOY_DATABASE`; it is
not inferred from an uploaded application's `[database]` section.

To test Compose, two independent application hostnames, updates, failed builds,
service restarts and wildcard TLS with a test certificate, run:

```sh
bash test/integration/compose.sh
bash test/integration/compose.sh mysql
```

To exercise the entire CLI, HTTP and Docker worker flow, run:

```sh
bash test/integration/http-docker.sh
```

To test the Docker lifecycle directly against its isolated fixture, run:

```sh
bash test/integration/run.sh sqlite
bash test/integration/run.sh mysql
```

These tests build Docker images and exercise HTTP routing, assets, persistent
storage, dependency/install hooks, cron, migrations, failed releases and shutdown.
Docker resources created by the test are labelled and removed on exit.
