<?php

declare(strict_types=1);

namespace Enigma\Tests;

use DateTimeImmutable;
use Enigma\GoogleChatHandler;
use Enigma\GoogleChatLogServiceProvider;
use Monolog\Level;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        GoogleChatHandler::$additionalLogs = null;
    }

    protected function tearDown(): void
    {
        GoogleChatHandler::$additionalLogs = null;

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [GoogleChatLogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'Test App');
        $app['config']->set('app.env', 'testing');
        $app['config']->set('logging.channels.google-chat.url', 'https://chat.googleapis.com/v1/spaces/AAA/messages');
    }

    /**
     * Build a log record for the given level and message.
     *
     * @param  array<string, mixed>  $context
     */
    protected function makeRecord(Level $level, string $message = 'Something went wrong', array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable('2026-07-24 10:00:00'),
            channel: 'testing',
            level: $level,
            message: $message,
            context: $context,
        );
    }

    /**
     * Handle a record through a fresh handler instance.
     */
    protected function handle(LogRecord $record): void
    {
        (new GoogleChatHandler($record->level))->handle($record);
    }
}
