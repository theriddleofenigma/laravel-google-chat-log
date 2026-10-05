# Changelog

All notable changes to `laravel-google-chat-log` will be documented in this file.

## v3.2.0

Reliability and per-channel configuration. Every new option is opt-in and
defaults to the 3.1 behaviour; there are no breaking changes.

### Added

- Per-channel configuration: pass `'with' => ['channel' => '<name>']` to read
  that channel's config, or `'with' => ['config' => [...]]` for on-demand
  channels. Several Google Chat channels can now run side by side with their
  own webhooks, levels, mentions and options.
- `GoogleChatHandler::__construct()` accepts the optional `$channel` and
  `$config` arguments after `$level` and `$bubble`.
- New `Enigma\ChannelConfig` class, which reads a channel's config with the
  3.x defaults applied.
- `LOG_GOOGLE_CHAT_LEVEL`, which overrides `LOG_LEVEL` for the channel.
- `enabled` (`LOG_GOOGLE_CHAT_ENABLED`, default `true`) and `environments`
  (`LOG_GOOGLE_CHAT_ENVIRONMENTS`, default every environment).
- `timeout` and `connect_timeout` (default `null`, meaning Laravel's HTTP
  client defaults).
- `retries` (default `0`), `retry_delay` and `retry_max_delay`: retries 429 and
  5xx responses with exponential backoff and jitter, honouring `Retry-After`.
- `fallback_channel` (default `null`): a log channel that receives a masked
  report when a message can not be delivered.
- `on_missing_url`: `throw` (default, unchanged), `ignore` or `warn`.
- Per-channel additional logs: an invokable class-string in the channel's
  `additional_logs` key (safe with `config:cache`), or
  `GoogleChatHandler::additionalLogsFor($channel, $closure)`. The global
  `GoogleChatHandler::$additionalLogs` remains the fallback.
- `GoogleChatHandler::flushState()` to reset static state, mainly for tests.
- `php artisan google-chat-log:test {--channel=} {--level=}` sends a sample
  card and diagnoses the response.
- README sections on multiple channels, reliability, the configuration
  reference and Google Chat limits & sizing.

### Fixed

- Every channel used the `logging.channels.google-chat` settings, so a second
  channel silently used the first channel's webhook, level and mentions.
- Connection errors and other exceptions thrown while sending escaped the
  logger and could break the request that logged. Sending is now wrapped and
  failures are reported to `fallback_channel` (or ignored when unset). Errors
  raised by the additional-logs hook still throw, as before.
- A log message written while a Google Chat message was being delivered (for
  example by the fallback channel or the additional-logs hook) could loop back
  into the handler. A re-entrancy guard now drops it.
- Webhook keys and tokens could appear in exception messages. All reported
  urls and errors are masked as `spaces/{SPACE_ID}/messages?key=***&token=***`.
- Messages over Google's 32,000 byte limit were rejected outright: the card
  title held the full, unbounded log message, and the 4,096 character text
  cap could still exceed the limit once JSON escaped. Payloads over 30,000
  bytes are now trimmed (widgets, then text, then title) with a
  `[truncated]` marker; smaller payloads are sent byte-for-byte as before.
- The HTTP response is now checked; failed deliveries are reported to
  `fallback_channel` when one is set (silent otherwise, as before).

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
