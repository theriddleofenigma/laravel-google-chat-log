<?php

declare(strict_types=1);

use Enigma\GoogleChatHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Google Chat log channel
    |--------------------------------------------------------------------------
    |
    | This array is merged into "logging.channels.google-chat" by the package
    | service provider, so a fresh install works with nothing more than the
    | LOG_GOOGLE_CHAT_WEBHOOK_URL environment variable. Anything you define in
    | your application's config/logging.php always takes precedence over the
    | defaults below.
    |
    | For additional Google Chat channels with their own settings, use the
    | "custom" driver with 'via' => Enigma\GoogleChatLogger::class (see the
    | README, "Multiple channels").
    |
    */

    'driver' => 'monolog',

    'handler' => GoogleChatHandler::class,

    // One or more webhook urls. Multiple urls may be provided either as an
    // array or as a single comma separated string.
    'url' => env('LOG_GOOGLE_CHAT_WEBHOOK_URL'),

    // Minimum level sent to Google Chat. LOG_GOOGLE_CHAT_LEVEL (since 3.2)
    // takes precedence over LOG_LEVEL for this channel only.
    'level' => env('LOG_GOOGLE_CHAT_LEVEL', env('LOG_LEVEL', 'debug')),

    // Set to false to turn the channel off entirely, e.g. locally or in CI.
    // Disabled channels send nothing and never complain about a missing url.
    'enabled' => env('LOG_GOOGLE_CHAT_ENABLED', true),

    // Only deliver in these environments (an array, or a comma separated
    // string such as "production,staging"). Null delivers in every one.
    'environments' => env('LOG_GOOGLE_CHAT_ENVIRONMENTS'),

    // What to do when no webhook url is configured: "throw" a RuntimeException
    // (default), "ignore" silently, or "warn" once per process to the
    // fallback_channel and then drop messages.
    'on_missing_url' => env('LOG_GOOGLE_CHAT_ON_MISSING_URL', 'throw'),

    // HTTP timeouts in seconds. Null uses the Laravel HTTP client defaults
    // (30 second timeout, 10 second connect timeout).
    'timeout' => env('LOG_GOOGLE_CHAT_TIMEOUT'),
    'connect_timeout' => env('LOG_GOOGLE_CHAT_CONNECT_TIMEOUT'),

    // Retries for 429 (rate limited) and 5xx responses only, using
    // exponential backoff with jitter. A Retry-After header from Google is
    // honoured. Delays are in milliseconds; retry_max_delay caps every wait,
    // including Retry-After. Retries run synchronously in the request.
    'retries' => env('LOG_GOOGLE_CHAT_RETRIES', 0),
    'retry_delay' => 500,
    'retry_max_delay' => 10000,

    // A log channel (e.g. "daily") that receives a short report whenever a
    // message can not be delivered. Webhook urls are always masked. Null keeps
    // delivery failures silent.
    'fallback_channel' => env('LOG_GOOGLE_CHAT_FALLBACK_CHANNEL'),

    // An invokable class (receives the Monolog LogRecord, returns an array)
    // adding extra key-value widgets to this channel's messages. Use a class
    // name rather than a closure so "php artisan config:cache" keeps working.
    'additional_logs' => null,

    // User ids that should be @mentioned for the given log level. Use "all" to
    // mention everyone in the space. Multiple ids may be comma separated.
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

];
