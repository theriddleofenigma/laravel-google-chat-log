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
    */

    'driver' => 'monolog',

    'handler' => GoogleChatHandler::class,

    // One or more webhook urls. Multiple urls may be provided either as an
    // array or as a single comma separated string.
    'url' => env('LOG_GOOGLE_CHAT_WEBHOOK_URL'),

    'level' => env('LOG_LEVEL', 'debug'),

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
