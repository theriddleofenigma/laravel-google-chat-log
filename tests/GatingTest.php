<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Level;

class GatingTest extends TestCase
{
    public function test_a_disabled_channel_sends_nothing(): void
    {
        Http::fake();

        config(['logging.channels.google-chat.enabled' => false]);

        $this->handle($this->makeRecord(Level::Emergency));

        Http::assertNothingSent();
    }

    public function test_a_disabled_channel_does_not_require_a_url(): void
    {
        Http::fake();

        config([
            'logging.channels.google-chat.enabled' => 'false',
            'logging.channels.google-chat.url' => null,
        ]);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertNothingSent();
    }

    public function test_a_disabled_handler_is_not_handling(): void
    {
        config(['logging.channels.google-chat.enabled' => false]);

        $this->assertFalse((new GoogleChatHandler)->isHandling($this->makeRecord(Level::Error)));
    }

    public function test_it_only_sends_in_allowed_environments(): void
    {
        Http::fake();

        config(['logging.channels.google-chat.environments' => 'production, staging']);
        $this->handle($this->makeRecord(Level::Error));
        Http::assertNothingSent();

        config(['logging.channels.google-chat.environments' => ['staging', 'testing']]);
        $this->handle($this->makeRecord(Level::Error));
        Http::assertSentCount(1);
    }

    public function test_an_empty_environment_list_allows_every_environment(): void
    {
        Http::fake();

        config(['logging.channels.google-chat.environments' => '']);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSentCount(1);
    }

    public function test_the_google_chat_level_takes_precedence_over_log_level(): void
    {
        $config = $this->packageConfig(['LOG_LEVEL' => 'debug', 'LOG_GOOGLE_CHAT_LEVEL' => 'error']);
        $this->assertSame('error', $config['level']);

        $config = $this->packageConfig(['LOG_LEVEL' => 'warning']);
        $this->assertSame('warning', $config['level']);

        $config = $this->packageConfig([]);
        $this->assertSame('debug', $config['level']);
    }

    public function test_the_channel_level_is_applied_through_the_log_manager(): void
    {
        Http::fake();

        config(['logging.channels.google-chat.level' => 'error']);

        Log::channel('google-chat')->warning('Too low');
        Http::assertNothingSent();

        Log::channel('google-chat')->error('High enough');
        Http::assertSentCount(1);
    }

    /**
     * Evaluate the package config file with the given environment variables.
     *
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    protected function packageConfig(array $env): array
    {
        $keys = ['LOG_LEVEL', 'LOG_GOOGLE_CHAT_LEVEL'];

        foreach ($keys as $key) {
            $this->setEnv($key, $env[$key] ?? null);
        }

        try {
            return require __DIR__.'/../config/google-chat.php';
        } finally {
            foreach ($keys as $key) {
                $this->setEnv($key, null);
            }
        }
    }

    protected function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}
