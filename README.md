Deployment tools for PHP projects.
==================================

[![CI](https://github.com/PhpGt/Deploy/actions/workflows/ci.yml/badge.svg)](https://github.com/PhpGt/Deploy/actions/workflows/ci.yml)

PHP.Gt/Deploy is the foundation for deployment automation in PHP projects,
including WebEngine. It provides a standalone CLI and a command class intended
for integration with `gt deploy` in PHP.Gt/GtCommand.

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

## Quality assurance

```sh
composer test
```

Runs PHPUnit, PHPStan, PHP_CodeSniffer, and PHPMD. Each tool is also available
individually through `composer phpunit`, `composer phpstan`, `composer phpcs`,
and `composer phpmd`. GitHub Actions runs these checks on PHP 8.3, 8.4, and 8.5,
plus Composer validation and dependency auditing.

Released under the MIT licence. See [CONTRIBUTING.md](CONTRIBUTING.md) to contribute.
