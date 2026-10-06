Deployment tools for PHP projects.
==================================

PHP.GT/Deploy is the foundation for deployment automation in PHP projects, including WebEngine. It provides a standalone CLI and a command class intended for integration with `gt deploy` in PHP.GT/GtCommand.

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

Deployment is not implemented yet. Running the command prints a message to
standard error and exits with status `1`; it does not deploy anything.

## Development

Requires PHP 8.3 or later and Composer. From a checkout:

```sh
composer install
php bin/deploy --help
php bin/deploy
```

The CLI entry point is `bin/deploy`, backed by `GT\Deploy\Cli\RunCommand`.
GtCommand integration will follow separately.
