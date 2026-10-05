<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use RuntimeException;

class MissingUrlTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.channels.google-chat.url', null);
    }

    public function test_it_throws_by_default(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Chat webhook url is not configured.');

        $this->handle($this->makeRecord(Level::Error));
    }

    public function test_an_unknown_mode_throws(): void
    {
        config(['logging.channels.google-chat.on_missing_url' => 'explode']);

        $this->expectException(RuntimeException::class);

        $this->handle($this->makeRecord(Level::Error));
    }

    public function test_it_can_ignore_a_missing_url(): void
    {
        Http::fake();
        Log::shouldReceive('channel')->never();

        config([
            'logging.channels.google-chat.on_missing_url' => 'ignore',
            'logging.channels.google-chat.fallback_channel' => 'audit',
        ]);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertNothingSent();
    }

    public function test_it_warns_once_per_process(): void
    {
        Http::fake();

        $fallback = new FakeLogger;
        Log::shouldReceive('channel')->with('audit')->andReturn($fallback);

        config([
            'logging.channels.google-chat.on_missing_url' => 'warn',
            'logging.channels.google-chat.fallback_channel' => 'audit',
        ]);

        $this->handle($this->makeRecord(Level::Error));
        $this->handle($this->makeRecord(Level::Error));

        Http::assertNothingSent();
        $this->assertCount(1, $fallback->records);
        $this->assertStringContainsString('webhook url is not configured', $fallback->records[0]['message']);
    }

    public function test_warn_without_a_fallback_channel_is_silent(): void
    {
        Http::fake();

        config(['logging.channels.google-chat.on_missing_url' => 'warn']);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertNothingSent();
    }
}
