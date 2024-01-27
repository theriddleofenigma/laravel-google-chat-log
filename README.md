<p align="center"><code>&hearts; Made with &lt;love/&gt; And I love &lt;code/&gt;</code></p>

# Laravel Google Chat Log

Brings up the option for sending the logs to google chat [Google Workspace formerly called GSuite] from [Laravel](https://laravel.com)/[Lumen](https://lumen.laravel.com).

## Installation
### Composer install
```shell
composer require theriddleofenigma/laravel-google-chat-log
```

For laravel 9.x or lower, please use v1.x
```shell
composer require theriddleofenigma/laravel-google-chat-log:^1.3
```

Add the following code to the channels array in `config/logging.php` in your laravel/lumen application.
```php
'google-chat' => [
    'driver' => 'monolog',
    'url' => env('LOG_GOOGLE_CHAT_WEBHOOK_URL'),
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
    'level' => env('LOG_LEVEL', 'debug'),
    'handler' => \Enigma\GoogleChatHandler::class,
],
```

You can provide the eight logging levels defined in the [RFC 5424 specification](https://tools.ietf.org/html/rfc5424): `emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, and `debug`

<b>Note*:</b> Make sure to set the <b>LOG_GOOGLE_CHAT_WEBHOOK_URL</b> env variable.
And all other <b>LOG_GOOGLE_CHAT_NOTIFY_USER_ID</b> are optional.
Here, you can set multiple google chat webhook url as comma separated value for the <b>LOG_GOOGLE_CHAT_WEBHOOK_URL</b> env variable.

<b>Note*:</b> For lumen, make sure the `$app->withFacades();` is uncommented in the <b>bootstrap/app.php</b>.

Now, you can notify a specific user with `@mention` in the error log by setting the corresponding USER_ID to the `LOG_GOOGLE_CHAT_NOTIFY_USER_ID_DEFAULT` env variable. User Ids mapped under `LOG_GOOGLE_CHAT_NOTIFY_USER_ID_DEFAULT` will be notified for all log levels.  

For getting the <b>USER_ID</b>, right-click the user-icon of the person whom you want to notify in the Google chat from your browser window and select inspect. Under the `div` element find the attribute data_member_id, then the USER_ID can be found as `data-member-id="user/human/{USER_ID}>"`.

In order to notify all the users like `@all`, Set ```LOG_GOOGLE_CHAT_NOTIFY_USER_ID_DEFAULT=all```. Also, you can set multiple USER_IDs as comma separated value.
In order to notify different users for different log levels, you can set the corresponding env keys mentioned to configure in the `logging.php` file.

Now, you can add custom additional logs to the Google chat message by passing a closure function to the GoogleChatHandler::$additionalLogs property.
```php
use Enigma\GoogleChatHandler;
use Illuminate\Http\Request;

class AppServiceProvider {
    public function register() {}
    public function boot() {
        GoogleChatHandler::$additionalLogs = function () {
            return [
                'tenant' => request()->user()?->tenant->name,
                'request' => json_encode(request()->toArray()),
            ];
        };
    }
}
```

## License

Copyright © Kumaravel

Laravel Google Chat Log is open-sourced software licensed under the [MIT license](LICENSE).
