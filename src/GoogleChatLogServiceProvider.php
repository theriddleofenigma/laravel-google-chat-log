<?php

declare(strict_types=1);

namespace Enigma;

use Illuminate\Support\ServiceProvider;

class GoogleChatLogServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     *
     * The channel definition is merged into the application's logging config
     * so a fresh install works out of the box - users only need to set the
     * LOG_GOOGLE_CHAT_WEBHOOK_URL environment variable and log to the
     * "google-chat" channel. Any channel config the application already
     * defines takes precedence over these defaults.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/google-chat.php',
            'logging.channels.google-chat'
        );
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/google-chat.php' => config_path('google-chat.php'),
            ], 'google-chat-log-config');
        }
    }
}
