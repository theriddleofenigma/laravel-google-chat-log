<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use RuntimeException;

class DeliveryFailureTest extends TestCase
{
    protected const SECRET_URL = 'https://chat.googleapis.com/v1/spaces/AAA/messages?key=SECRET-KEY&token=SECRET-TOKEN';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.channels.google-chat.url', self::SECRET_URL);
    }

    public function test_write_never_throws_on_connection_errors(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host for '.self::SECRET_URL));

        $this->handle($this->makeRecord(Level::Error));

        $this->addToAssertionCount(1);
    }

    public function test_write_never_throws_on_unexpected_errors(): void
    {
        Http::fake(fn () => throw new RuntimeException('Boom'));

        Log::channel('google-chat')->error('Still fine');

        $this->addToAssertionCount(1);
    }

    public function test_failures_are_silent_without_a_fallback_channel(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        Log::shouldReceive('channel')->never();

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSentCount(1);
    }

    public function test_connection_errors_are_reported_to_the_fallback_channel_with_a_masked_url(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host for '.self::SECRET_URL));
        $fallback = $this->fakeFallback();

        $this->handle($this->makeRecord(Level::Error));

        $this->assertCount(1, $fallback->records);
        $record = $fallback->records[0];
        $this->assertSame('Google Chat log delivery failed.', $record['message']);
        $this->assertSame('spaces/AAA/messages?key=***&token=***', $record['context']['webhook']);
        $this->assertSame(ConnectionException::class, $record['context']['exception']);
        $this->assertSame('cURL error 6: Could not resolve host for spaces/AAA/messages?key=***&token=***', $record['context']['error']);
        $this->assertSecretsAbsent($fallback);
    }

    public function test_rejected_responses_are_reported_to_the_fallback_channel(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid token: SECRET-TOKEN, see '.self::SECRET_URL]], 400)]);
        $fallback = $this->fakeFallback();

        $this->handle($this->makeRecord(Level::Error));

        $this->assertCount(1, $fallback->records);
        $this->assertSame('Google Chat rejected a log message.', $fallback->records[0]['message']);
        $this->assertSame(400, $fallback->records[0]['context']['status']);
        $this->assertSame('google-chat', $fallback->records[0]['context']['channel']);
        $this->assertSecretsAbsent($fallback);
    }

    public function test_successful_responses_are_not_reported(): void
    {
        Http::fake();
        $fallback = $this->fakeFallback();

        $this->handle($this->makeRecord(Level::Error));

        $this->assertSame([], $fallback->records);
    }

    public function test_a_failing_fallback_channel_does_not_throw(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Log::shouldReceive('channel')->andThrow(new RuntimeException('Fallback down'));
        config(['logging.channels.google-chat.fallback_channel' => 'audit']);

        $this->handle($this->makeRecord(Level::Error));

        $this->addToAssertionCount(1);
    }

    public function test_the_fallback_channel_can_not_loop_back_into_the_handler(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        config([
            'logging.channels.google-chat.fallback_channel' => 'loop',
            'logging.channels.loop' => ['driver' => 'stack', 'channels' => ['google-chat']],
        ]);

        Log::channel('google-chat')->error('First failure');

        // Only the original message was posted; the fallback report was dropped.
        Http::assertSentCount(1);
    }

    public function test_logging_from_an_additional_logs_hook_can_not_loop(): void
    {
        Http::fake();

        GoogleChatHandler::$additionalLogs = function () {
            Log::channel('google-chat')->error('Logged from inside the hook');

            return [];
        };

        Log::channel('google-chat')->error('Outer');

        Http::assertSentCount(1);
    }

    public function test_the_guard_is_released_after_a_delivery(): void
    {
        Http::fake();

        $this->handle($this->makeRecord(Level::Error));
        $this->handle($this->makeRecord(Level::Error));

        Http::assertSentCount(2);
    }

    public function test_the_guard_is_released_when_message_building_throws(): void
    {
        Http::fake();

        GoogleChatHandler::$additionalLogs = fn () => 'not-an-array';

        try {
            $this->handle($this->makeRecord(Level::Error));
        } catch (\InvalidArgumentException) {
            // Expected: the existing contract for invalid hook results.
        }

        GoogleChatHandler::$additionalLogs = null;
        $this->handle($this->makeRecord(Level::Error));

        Http::assertSentCount(1);
    }

    protected function fakeFallback(): FakeLogger
    {
        $fallback = new FakeLogger;
        Log::shouldReceive('channel')->with('audit')->andReturn($fallback);
        config(['logging.channels.google-chat.fallback_channel' => 'audit']);

        return $fallback;
    }

    protected function assertSecretsAbsent(FakeLogger $fallback): void
    {
        $dump = json_encode($fallback->records);

        $this->assertStringNotContainsString('SECRET-KEY', $dump);
        $this->assertStringNotContainsString('SECRET-TOKEN', $dump);
    }
}
