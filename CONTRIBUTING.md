# Contributing

Thanks for taking the time to contribute! Contributions of all kinds are
welcome &mdash; bug reports, feature requests, documentation, and code.

## Code of Conduct

This project adheres to a [Code of Conduct](CODE_OF_CONDUCT.md). By
participating, you are expected to uphold it. Please report unacceptable
behaviour to the maintainer.

## Reporting bugs & requesting features

- Search the [existing issues](https://github.com/theriddleofenigma/laravel-google-chat-log/issues)
  first to avoid duplicates.
- Open a new issue using the appropriate template and include as much detail as
  you can (a minimal reproduction goes a long way).
- For usage questions, please use
  [Discussions](https://github.com/theriddleofenigma/laravel-google-chat-log/discussions)
  rather than the issue tracker.

## Development setup

This package targets **PHP 8.2+** and **Laravel 11 / 12 / 13**.

```shell
git clone https://github.com/theriddleofenigma/laravel-google-chat-log.git
cd laravel-google-chat-log
composer install
```

## Running the tests

The suite uses [PHPUnit](https://phpunit.de/) and
[Orchestra Testbench](https://github.com/orchestral/testbench):

```shell
composer test
```

Please make sure the whole suite passes and add coverage for any behaviour you
change or add. The tests fake the HTTP client, so no real Google Chat webhook
is needed to run them.

## Coding style

Code style is enforced with [Laravel Pint](https://laravel.com/docs/pint).
Check and fix your changes before committing:

```shell
composer lint    # report style violations (used in CI)
composer format  # fix them automatically
```

## Pull request guidelines

1. Fork the repository and create your branch from `main`.
2. Keep each pull request focused on a single concern.
3. Follow the existing code style, enforced by Pint (`composer lint`).
4. Add or update tests for your change.
5. Update the documentation (`README.md`) and the `CHANGELOG.md` "Unreleased"
   section where relevant.
6. Ensure CI is green.

## Reporting security issues

Please do **not** open a public issue for security vulnerabilities. See
[SECURITY.md](SECURITY.md) for how to report them privately.
