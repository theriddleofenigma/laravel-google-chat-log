<p align="center"><code>&hearts; Made with &lt;love/&gt; And I love &lt;code/&gt;</code></p>

# Laravel Google Chat Log

[![Tests](https://github.com/theriddleofenigma/laravel-google-chat-log/actions/workflows/tests.yml/badge.svg)](https://github.com/theriddleofenigma/laravel-google-chat-log/actions/workflows/tests.yml)
[![Lint](https://github.com/theriddleofenigma/laravel-google-chat-log/actions/workflows/lint.yml/badge.svg)](https://github.com/theriddleofenigma/laravel-google-chat-log/actions/workflows/lint.yml)

Send your [Laravel](https://laravel.com)/[Lumen](https://lumen.laravel.com) application logs straight to a Google Chat space [Google Workspace, formerly GSuite] via incoming webhooks.

## Requirements

- PHP `^8.2`
- Laravel 11 or 12 (`illuminate/support` `^11.0|^12.0`)

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
    'level' => env('LOG_LEVEL', 'debug'),
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

## Testing

```shell
composer test
```

This runs Laravel Pint (code style) and the PHPUnit suite.

## License

Copyright © Kumaravel

Laravel Google Chat Log is open-sourced software licensed under the [MIT license](LICENSE).
