<p align="center"><code>&hearts; Made with &lt;love/&gt; And I love &lt;code/&gt;</code></p>

# Laravel Google Chat Log

[![Tests](https://github.com/theriddleofenigma/laravel-google-chat-log/actions/workflows/tests.yml/badge.svg)](https://github.com/theriddleofenigma/laravel-google-chat-log/actions/workflows/tests.yml)
[![Lint](https://github.com/theriddleofenigma/laravel-google-chat-log/actions/workflows/lint.yml/badge.svg)](https://github.com/theriddleofenigma/laravel-google-chat-log/actions/workflows/lint.yml)

Send your [Laravel](https://laravel.com)/[Lumen](https://lumen.laravel.com) application logs straight to a Google Chat space [Google Workspace, formerly GSuite] via incoming webhooks.

## Requirements

- PHP `8.2`, `8.3`, `8.4` or `8.5` (Laravel 13 requires PHP `8.3+`; Laravel 11
  is only tested on PHP `8.2`-`8.4`)
- Laravel 11, 12 or 13 (`illuminate/support` `^11.0|^12.0|^13.0`)

> For Laravel 10 use `^2.x`. For Laravel 9 or lower use `^1.x`.

## Installation

```shell
composer require theriddleofenigma/laravel-google-chat-log
```

The package ships with Laravel package auto-discovery, so the service provider
is registered for you and it automatically adds a `google-chat` logging
channel. In most cases the only thing you need to do is set the webhook url:

```dotenv
LOG_GOOGLE_CHAT_WEBHOOK_URL=https://chat.googleapis.com/v1/spaces/XXXX/messages?key=...&token=...
```

Then log to the channel:

```php
use Illuminate\Support\Facades\Log;

Log::channel('google-chat')->error('Something went wrong');
```

To route your default log stack through Google Chat, add `google-chat` to the
`stack` channel in `config/logging.php`, or set `LOG_CHANNEL=google-chat`.

### Customising the channel

The channel is registered with sensible defaults, but anything you define in
your application's `config/logging.php` always takes precedence. You can
publish the channel definition to tweak it:

```shell
php artisan vendor:publish --tag=google-chat-log-config
```

Or define it manually in the `channels` array of `config/logging.php`:

```php
'google-chat' => [
    'driver' => 'monolog',
    'handler' => \Enigma\GoogleChatHandler::class,
    'url' => env('LOG_GOOGLE_CHAT_WEBHOOK_URL'),
    'level' => env('LOG_GOOGLE_CHAT_LEVEL', env('LOG_LEVEL', 'debug')),
    'notify_users' => [
        'default' => env('LOG_GOOGLE_CHAT_NOTIFY_USER_ID_DEFAULT'),
        'emergency' => env('LOG_GOOGLE_CHAT_NOTIFY_USER_ID_EMERGENCY'),
        'alert' => env('LOG_GOOGLE_CHAT_NOTIFY_USER_ID_ALERT'),
        'critical' => env('LOG_GOOGLE_CHAT_NOTIFY_USER_ID_CRITICAL'),
        'error' => env('LOG_GOOGLE_CHAT_NOTIFY_USER_ID_ERROR'),
        'warning' => env('LOG_GOOGLE_CHAT_NOTIFY_USER_ID_WARNING'),
        'notice' => env('LOG_GOOGLE_CHAT_NOTIFY_USER_ID_NOTICE'),
        'info' => env('LOG_GOOGLE_CHAT_NOTIFY_USER_ID_INFO'),
        'debug' => env('LOG_GOOGLE_CHAT_NOTIFY_USER_ID_DEBUG'),
    ],
],
```

> **Lumen:** make sure `$app->withFacades();` is uncommented in
> `bootstrap/app.php`, and register `Enigma\GoogleChatLogServiceProvider`.

You can provide the eight logging levels defined in the
[RFC 5424 specification](https://tools.ietf.org/html/rfc5424): `emergency`,
`alert`, `critical`, `error`, `warning`, `notice`, `info`, and `debug`.

### Multiple webhook urls

Set multiple Google Chat webhook urls as a comma separated value for the
`LOG_GOOGLE_CHAT_WEBHOOK_URL` env variable (or as an array in the channel
config) and every space receives the log.

### Multiple channels

Since 3.2 every channel can have its own webhook, level, mentions and options.
The recommended way is a `custom` driver channel built by
`Enigma\GoogleChatLogger`. Laravel hands it the whole channel config, so every
key is read from the channel itself:

```php
'google-chat-ops' => [
    'driver' => 'custom',
    'via' => \Enigma\GoogleChatLogger::class,
    'name' => 'google-chat-ops',
    'url' => env('LOG_GOOGLE_CHAT_OPS_WEBHOOK_URL'),
    'level' => 'critical',
    'notify_users' => ['default' => 'all'],
],
```

`name` is optional. As with any Laravel channel it becomes the Monolog channel
name in the message text (the environment name when omitted), and it is the
name to use with [`additionalLogsFor()`](#per-channel-additional-logs). Laravel
does not tell a custom factory the channel's key, so a channel without `name`
is anonymous: `additionalLogsFor()` can not target it, and it never picks up
hooks registered for `google-chat` (the `additional_logs` class and the global
closure still apply). The
factory builds the channel like Laravel's `monolog` driver: same default
formatter, and `formatter`, `processors` and `action_level` work as usual.

With the `monolog` driver, Laravel only passes the handler the keys under
`with`, so tell it which channel config to read:

```php
'google-chat-ops' => [
    'driver' => 'monolog',
    'handler' => \Enigma\GoogleChatHandler::class,
    'with' => ['channel' => 'google-chat-ops'],
    'url' => env('LOG_GOOGLE_CHAT_OPS_WEBHOOK_URL'),
    'level' => 'critical',
    'notify_users' => ['default' => 'all'],
],
```

A `monolog` channel without `with.channel` reads `logging.channels.google-chat`,
as in earlier releases, even if it sets its own `url`. `php artisan
google-chat-log:test --channel=<name>` warns about that case. Keys missing from
a channel use the defaults listed in
[Configuration reference](#configuration-reference), so a channel only needs the
keys it changes.

For on-demand channels, pass the whole config instead:

```php
Log::build([
    'driver' => 'monolog',
    'handler' => \Enigma\GoogleChatHandler::class,
    'with' => ['config' => ['url' => 'https://chat.googleapis.com/v1/spaces/...']],
])->error('Something went wrong');
```

## Reliability

Since 3.2, a failed delivery never throws out of the logger: connection errors,
timeouts and rejected responses are caught, so logging can not break the
request that logged. Everything below is opt-in; the defaults behave exactly
like 3.1.

- **Fallback channel.** Set `LOG_GOOGLE_CHAT_FALLBACK_CHANNEL=daily` to get a
  short report (masked webhook, HTTP status or error) whenever a message can
  not be delivered. Without it, failures stay silent. Webhook urls are always
  masked as `spaces/{SPACE_ID}/messages?key=***&token=***`.
- **Timeouts.** `LOG_GOOGLE_CHAT_TIMEOUT` and `LOG_GOOGLE_CHAT_CONNECT_TIMEOUT`
  (seconds). Unset, Laravel's HTTP client defaults apply (30s / 10s), so a slow
  webhook can stall a request for that long. `5` and `2` are good values.
- **Retries.** `LOG_GOOGLE_CHAT_RETRIES=2` retries `429` and `5xx` responses
  only, with exponential backoff and jitter (`retry_delay`, default 500 ms,
  doubling per attempt) and honours Google's `Retry-After` header up to
  `retry_max_delay` (default 10000 ms), which caps every wait. Retries run
  synchronously, inside the request that logged.
- **Enable switch and environments.** `LOG_GOOGLE_CHAT_ENABLED=false` turns the
  channel off; `LOG_GOOGLE_CHAT_ENVIRONMENTS=production,staging` limits it to
  those environments.
- **Missing url.** `LOG_GOOGLE_CHAT_ON_MISSING_URL` is `throw` by default (a
  `RuntimeException`, as before), `ignore` to drop messages silently, or `warn`
  to drop them and report once per process to the fallback channel.
- **Message size.** Google rejects messages over 32,000 bytes. When a payload
  goes over 30,000 bytes the package trims it: additional-log widgets first,
  then the text, then the card title, each marked `[truncated]`. Smaller
  messages are sent unchanged.

## Testing the webhook

```shell
php artisan google-chat-log:test
php artisan google-chat-log:test --channel=google-chat-ops --level=critical
```

The command sends a sample card to every webhook of the channel and explains
the response:

| Status | Meaning |
|---|---|
| `200` | Delivered. |
| `403` | Incoming webhooks are probably disabled by your Workspace admin. |
| `404` | The webhook or the space was deleted. |
| `429` | The space's rate limit was hit. |

It exits with a non-zero code on any failure, and warns (but still sends) when
`enabled` or `environments` would block normal delivery.

## Notifying users with `@mention`

Notify a specific user by setting the corresponding user id. Ids mapped under
`LOG_GOOGLE_CHAT_NOTIFY_USER_ID_DEFAULT` are notified for **all** log levels:

```dotenv
LOG_GOOGLE_CHAT_NOTIFY_USER_ID_DEFAULT=1234567890
```

To find a **USER_ID**, right-click the user icon of the person you want to
notify in Google Chat and select inspect. On the `div` element find the
`data-member-id` attribute — the id is the value in
`data-member-id="user/human/{USER_ID}"`.

To notify everyone (`@all`), set `LOG_GOOGLE_CHAT_NOTIFY_USER_ID_DEFAULT=all`.
Multiple ids can be provided as a comma separated value, and each log level can
target different users via its matching `LOG_GOOGLE_CHAT_NOTIFY_USER_ID_*` env
variable.

## Additional custom logs

Append extra key-value pairs to every Google Chat message by assigning a
closure to `GoogleChatHandler::$additionalLogs`. The closure receives the
current `Monolog\LogRecord` and must return an array:

```php
use Enigma\GoogleChatHandler;
use Monolog\LogRecord;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        GoogleChatHandler::$additionalLogs = function (LogRecord $record) {
            return [
                'tenant' => request()->user()?->tenant->name,
                'request' => request()->fullUrl(),
            ];
        };
    }
}
```

Non-string values are JSON encoded automatically.

### Per-channel additional logs

Since 3.2 a channel can have its own hook. Use an invokable class in the channel
config, which keeps `php artisan config:cache` working (closures can not be
cached):

```php
// config/logging.php
'google-chat' => [
    // ...
    'additional_logs' => \App\Logging\ChatExtras::class,
],

// app/Logging/ChatExtras.php
class ChatExtras
{
    public function __invoke(LogRecord $record): array
    {
        return ['tenant' => request()->user()?->tenant->name];
    }
}
```

Or register a closure for one channel from a service provider:

```php
GoogleChatHandler::additionalLogsFor('google-chat-ops', fn (LogRecord $record) => [
    'queue' => $record->context['queue'] ?? null,
]);
```

For each channel the first match wins: the registered closure, then the
`additional_logs` class, then the global `GoogleChatHandler::$additionalLogs`.

## Configuration reference

All keys are optional. A missing key uses the default shown, which matches the
behaviour of 3.1.

| Key | Env variable | Default | Since |
|---|---|---|---|
| `url` | `LOG_GOOGLE_CHAT_WEBHOOK_URL` | – (required) | 1.x |
| `level` | `LOG_GOOGLE_CHAT_LEVEL`, then `LOG_LEVEL` | `debug` | 3.2 (`LOG_GOOGLE_CHAT_LEVEL`) |
| `notify_users.default` | `LOG_GOOGLE_CHAT_NOTIFY_USER_ID_DEFAULT` | – | 1.x |
| `notify_users.{level}` | `LOG_GOOGLE_CHAT_NOTIFY_USER_ID_{LEVEL}` | – | 1.x |
| `enabled` | `LOG_GOOGLE_CHAT_ENABLED` | `true` | 3.2 |
| `environments` | `LOG_GOOGLE_CHAT_ENVIRONMENTS` | all | 3.2 |
| `on_missing_url` | `LOG_GOOGLE_CHAT_ON_MISSING_URL` | `throw` | 3.2 |
| `timeout` | `LOG_GOOGLE_CHAT_TIMEOUT` | Laravel default (30s) | 3.2 |
| `connect_timeout` | `LOG_GOOGLE_CHAT_CONNECT_TIMEOUT` | Laravel default (10s) | 3.2 |
| `retries` | `LOG_GOOGLE_CHAT_RETRIES` | `0` | 3.2 |
| `retry_delay` | – | `500` (ms) | 3.2 |
| `retry_max_delay` | – | `10000` (ms) | 3.2 |
| `fallback_channel` | `LOG_GOOGLE_CHAT_FALLBACK_CHANNEL` | none (silent) | 3.2 |
| `additional_logs` | – | none | 3.2 |

## Limits & sizing

**The throughput limit is the same on every Workspace plan. The plan only
decides whether incoming webhooks are available at all.**

### Availability by account type

| Account / plan | Incoming webhooks? | Notes |
|---|---|---|
| Personal Gmail account | No | Chat is free on personal accounts, but app webhooks are reserved for Workspace. |
| Workspace Business (Starter / Standard / Plus) | Yes, if the admin allows it | Google's [webhook quickstart](https://developers.google.com/workspace/chat/quickstart/webhooks) requires a Business or Enterprise account whose organization lets users add and use incoming webhooks. |
| Workspace Enterprise | Yes, if the admin allows it | Same throughput as Business. |
| Workspace Education / other editions | Verify | Not confirmed in Google's webhook quickstart. Test with `php artisan google-chat-log:test`. |

The admin setting is **Admin console → Apps → Google Workspace → Google Chat →
Chat apps → "Allow users to add and use incoming webhooks" = On**
([Google help](https://support.google.com/a/answer/7651360)). Only users in the
organization can create webhooks. A `403` from the test command usually means
this setting is off.

### Limits on every plan

- **1 request per second per space, shared by all webhooks in that space.**
  Chat may reject requests above that. A bigger plan does not raise it, and
  Google Cloud quota increases apply to per-project API quotas, not webhooks.
  ([source](https://developers.google.com/workspace/chat/quickstart/webhooks))
- Going over a quota returns **HTTP 429**; back off exponentially. Heavy traffic
  into one space can also hit internal limits that are not listed on the
  quotas page. ([source](https://developers.google.com/workspace/chat/limits))
- **32,000 bytes per message**, text and cards combined.
  ([source](https://developers.google.com/workspace/chat/create-messages))
- Rule of thumb: one space absorbs about **60 messages per minute** at most. Any
  level that can exceed that during an incident should not go to Chat at
  `debug`/`info`, or needs its own space.

### Sizing guidance

| Profile | Recommended settings |
|---|---|
| Hobby / single server | `LOG_GOOGLE_CHAT_LEVEL=error`, `LOG_GOOGLE_CHAT_TIMEOUT=5`, `LOG_GOOGLE_CHAT_CONNECT_TIMEOUT=2`, `LOG_GOOGLE_CHAT_RETRIES=2`. Keep `daily` in the stack next to `google-chat`. |
| Production, multiple servers | All of the above, plus `LOG_GOOGLE_CHAT_FALLBACK_CHANNEL=daily`. Use a separate channel (and space) per audience, see [Multiple channels](#multiple-channels). |
| High traffic / incident storms | Treat Chat as alerting: `LOG_GOOGLE_CHAT_LEVEL=critical` or a dedicated space for critical alerts. Send full log volume to a real log store. |
| Local / CI | `LOG_GOOGLE_CHAT_ENABLED=false`, or `LOG_GOOGLE_CHAT_ENVIRONMENTS=production`, or `LOG_GOOGLE_CHAT_ON_MISSING_URL=ignore`. |

```dotenv
# Recommended production baseline (all opt-in in 3.x)
LOG_GOOGLE_CHAT_LEVEL=error
LOG_GOOGLE_CHAT_TIMEOUT=5
LOG_GOOGLE_CHAT_CONNECT_TIMEOUT=2
LOG_GOOGLE_CHAT_RETRIES=2
LOG_GOOGLE_CHAT_FALLBACK_CHANNEL=daily
```

The 4.0 release plans to make `LOG_GOOGLE_CHAT_LEVEL=error`, the 5s / 2s
timeouts and 2 retries the defaults. Setting them now gives you 4.0's
behaviour on 3.x.

## Testing

```shell
composer test      # run the PHPUnit suite
composer lint      # check code style with Laravel Pint
composer format    # fix code style automatically
```

The live suite in `tests/Live` sends real messages and is never part of
`composer test`. Point it at a dedicated scratch space, because it uses that
space's 1 request per second quota:

```shell
LOG_GOOGLE_CHAT_LIVE_WEBHOOK="https://chat.googleapis.com/v1/spaces/..." composer test:live
```

The `Live` GitHub workflow runs it nightly and on demand, using the
`LOG_GOOGLE_CHAT_LIVE_WEBHOOK` repository secret. It never runs on pull
requests.

## Contributing

Contributions are welcome &mdash; please read the
[contributing guide](CONTRIBUTING.md) first. For security issues, see the
[security policy](SECURITY.md).

## License

Copyright © Kumaravel

Laravel Google Chat Log is open-sourced software licensed under the [MIT license](LICENSE).
