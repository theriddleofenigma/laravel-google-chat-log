<p align="center"><code>&hearts; Made with &lt;love/&gt; And I love &lt;code/&gt;</code></p>

# Laravel Google Chat Log

Brings up the option for sending the logs to google chat [GSuite] from [Laravel](https://laravel.com)/[Lumen](https://lumen.laravel.com).

## Installation
### Composer install
```shell
composer require theriddleofenigma/laravel-google-chat-log
```

Add the following code to the channels array in `config/logging.php` in your laravel/lumen application.
```
'google-chat' => [
    'driver' => 'monolog',
    'url' => env('LOG_GOOGLE_CHAT_WEBHOOK_URL'),
    'notify_user_id' => env('LOG_GOOGLE_CHAT_NOTIFY_USER_ID'),
    'level' => 'warning',
    'handler' => \Enigma\GoogleChatHandler::class,
],
```

You can provide the eight logging levels defined in the [RFC 5424 specification](https://tools.ietf.org/html/rfc5424): `emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, and `debug`

<b>Note*:</b> Make sure to set the <b>LOG_GOOGLE_WEBHOOK_URL</b> env variable. And <b>LOG_GOOGLE_CHAT_NOTIFY_USER_ID</b> is optional.

Now, you can notify a specific user with `@mention` in the error log by setting the corresponding USER_ID to the `LOG_GOOGLE_CHAT_NOTIFY_USER_ID` env variable. 

For getting the <b>USER_ID</b>, right-click the user-icon of the person whom you want to notify in the Google chat from your browser window and select inspect. Under the `div` element find the attribute data_member_id, then the USER_ID can be found as `data-member-id="user/human/{USER_ID}>"`.

In order to notify all the users like `@all`, Set ```LOG_GOOGLE_CHAT_NOTIFY_USER_ID=all```.

## License

Copyright © Kumaravel

Laravel Google Chat Log is open-sourced software licensed under the [MIT license](LICENSE).
