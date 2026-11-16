# PHP.GT deployment server

A WebEngine application for `deploy.php.gt`. It reserves anonymous deployment URLs
and accepts authenticated `.tar.gz` uploads and source initialisation.
Docker builds, application startup,
public progress pages and wildcard subdomain routing are not implemented yet.

## Run

Source extraction uses GNU tar and gzip on Linux, with timeout (coreutils) and
prlimit (util-linux) providing process limits. No additional scripting runtime is needed.

```sh
composer install
gt serve
```

In the client project's `config.dev.ini`, point `[deploy] server` at the printed
address, for example `http://localhost:9090`, then run `gt deploy`.

## API

Send `Accept: application/json` on both endpoints:

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

Tokens use GT\Ulid with 10 timestamp characters and 32 random characters. Only
SHA-256 token hashes are persisted. Expiry is verified from the authenticated
token's timestamp: tokens are valid for 24 hours from issuance.

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

Source is not executed. Composer installation, npm, asset builds, migrations and
Docker startup are still unimplemented in this HTTP flow. Expiry is enforced,
but automatic deletion of expired data has not been implemented. The PHP worker
needs write access to `data`.

## Nginx

Use this application's `www/` directory as the document root, not `server/`.
Send non-static requests to `www/index.php` through PHP-FPM. For example, inside
the `server_name deploy.php.gt` server block, with root and PHP-FPM configured:

```nginx
client_max_body_size 100m;

location / {
    try_files $uri /index.php$is_args$args;
}

location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}

location ~ \.php$ {
    return 404;
}
```

Adjust the PHP-FPM socket for the host. Configure HTTPS on this server separately.
The `data`, `class`, and `api` directories stay outside the public document root.

Set PHP's `post_max_size` to at least `100M` as well. Uploads are raw request bodies,
not multipart PHP file uploads. HTTPS and wildcard DNS require host configuration.

## Tests

From the parent Deploy directory, install its development dependencies with
`composer install` and the server dependencies with `composer install -d server`.
Run `composer test-server`, or `composer test` for both CLI and server checks.
Filesystem integration tests use isolated temporary directories and never alter
application records under `server/data`.
