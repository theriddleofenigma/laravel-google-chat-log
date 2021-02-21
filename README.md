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
    'level' => 'warning',
    'handler' => \Enigma\GoogleChatHandler::class,
],
```

You can provide the eight logging levels defined in the [RFC 5424 specification](https://tools.ietf.org/html/rfc5424): `emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, and `debug`

## License

Copyright © Kumaravel

Laravel Google Chat Log is open-sourced software licensed under the [MIT license](LICENSE.md).