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

After uploading, the CLI calls `POST /initialise/` to extract the source on the server.
The server does not build or run the application yet.
The public subdomain is not serving the application at this stage.
Local archives remain available at the printed path for inspection.

## Configuration and tokens

WebEngine defines `[deploy] server=deploy.php.gt` in `config.default.ini`.
To use a local server, add this to the project's `config.dev.ini` or `config.ini`:

```ini
[deploy]
server=http://localhost:9090
```

HTTPS is assumed when no scheme is supplied. The value is the server base URL,
not an API endpoint. Project config overrides are loaded normally.

The CLI writes `deploy.token` and `deploy.token_server` into `config.dev.ini`,
preserving existing settings and restricting file permissions to the owner.
The token is saved before packaging, so interrupted deployments can be retried.
Changing the server requires removing both saved token values before reserving
another deployment. Invalid or expired tokens never silently create a new one.

Tokens are generated with GT\Ulid and contain a timestamp plus 160 random bits.
They expire 24 hours after reservation; updating a deployment does not renew them.
The server stores a token hash, not the bearer token itself.

The server uses GNU tar with gzip, timeout and prlimit for bounded extraction. Extracted files live in
`server/data/deployment/<id>/source-<archive-id>/`; the record
`reservation.json` identifies the current source directory. An invalid archive
does not replace the previous source.

Ignore `config.*.ini` in Git. The CLI warns if `config.dev.ini` is not effectively
ignored, including when it is already tracked. It does not edit your project's
`.gitignore` automatically. Neither terminal output nor archives include the saved token.

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

## Docker runtime prototype

`resources/Dockerfile` builds applications using Nginx and PHP-FPM in one
container. Nginx serves `www/` assets and forwards only the WebEngine front
controller to PHP-FPM over loopback. The startup script supervises both services,
forwards shutdown and fails if either exits. CLI migration commands bypass the
HTTP services. No Apache runtime is used.

The upload API is not connected to this runtime yet. To test the existing Docker
prototype directly against its isolated fixture, run:

```sh
bash test/integration/run.sh sqlite
bash test/integration/run.sh mysql
```

These tests build Docker images and exercise HTTP routing, assets, persistent
storage, migrations, failed releases and shutdown. Docker resources created by
the test are labelled and removed on exit.
