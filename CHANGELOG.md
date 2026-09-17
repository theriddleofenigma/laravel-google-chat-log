# Changelog

All notable changes to `laravel-google-chat-log` will be documented in this file.

## v3.1.0

PHP 8.5 support and dependency refresh.

### Added

- Official support for PHP 8.5: the test matrix now covers PHP 8.2-8.5 across
  the supported Laravel versions (Laravel 11 stays on PHP 8.2-8.4, matching
  upstream support).

### Changed

- The `guzzlehttp/guzzle` constraint moved from `^7.0` to `^7.8.2|^8.0`,
  matching `laravel/framework` 13. This allows Guzzle 8 while staying
  compatible with Laravel 11 and 12, which still cap Guzzle at `^7`. Note that
  it also raises the minimum: applications pinned below Guzzle `7.8.2` need to
  upgrade Guzzle before upgrading this package.
- Development dependencies were refreshed: `laravel/pint` `^1.30`,
  `phpunit/phpunit` `^11.5|^12.0|^13.0` (PHPUnit 13 requires PHP 8.4+, so
  Composer resolves the newest suite each PHP version supports).
- The lint workflow now runs on PHP 8.4.

## v3.0.0

Renovation release.

### Added

- Auto-discovered `Enigma\GoogleChatLogServiceProvider` that registers the
  `google-chat` log channel automatically, so a fresh install works with only
  the `LOG_GOOGLE_CHAT_WEBHOOK_URL` environment variable set. This addresses
  the "missing service provider" confusion reported in the setup docs.
- A publishable `config/google-chat.php` channel definition.
- A full test suite built on Orchestra Testbench + PHPUnit.
- [Laravel Pint](https://laravel.com/docs/pint) for code style, plus GitHub
  Actions workflows for tests (PHP 8.2-8.4 × Laravel 11/12/13) and linting.
- The `GoogleChatHandler::$additionalLogs` closure now receives the current
  `Monolog\LogRecord` instance.

### Changed

- **Breaking:** dropped support for PHP < 8.2 and Laravel 10. Supported ranges
  are now PHP `^8.2` and `illuminate/support` `^11.0|^12.0|^13.0` (Laravel 13
  itself requires PHP 8.3+).
- Message building was extracted into a dedicated `Enigma\GoogleChatMessage`
  class and the handler was rewritten with `declare(strict_types=1)` and full
  type coverage.
- The `guzzlehttp/guzzle` constraint was tightened to `^7.0`.

### Fixed

- The card payload now emits `sections` as an array of section objects, as
  required by the Google Chat `cardsV2` schema (it was previously a single
  object).
- The record timestamp is now formatted as a readable string instead of being
  passed as a raw `DateTimeImmutable` object.
- Non-string additional log values are now reliably JSON encoded using
  `JSON_THROW_ON_ERROR` (the previous `try/catch` never triggered because
  `json_encode` did not throw), and the previously undefined `Throwable`
  reference was corrected.
- User id mention lists are now trimmed and de-duplicated correctly.
