<?php

declare(strict_types=1);

namespace Enigma\Tests\Live;

use Enigma\GoogleChatHandler;
use Enigma\GoogleChatMessage;
use Enigma\Support\PayloadBudget;
use Enigma\Tests\FakeLogger;
use Enigma\Tests\PackageTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Sends real messages to a Google Chat space.
 *
 * Runs only when LOG_GOOGLE_CHAT_LIVE_WEBHOOK is set, and never as part of
 * "composer test". Always point it at a dedicated scratch space: the suite
 * uses that space's 1 request per second quota.
 *
 *     LOG_GOOGLE_CHAT_LIVE_WEBHOOK="https://chat.googleapis.com/..." composer test:live
 */
class WebhookLiveTest extends PackageTestCase
{
    /**
     * Pause between sends, keeping the suite under 1 request per second.
     */
    protected const PACE_MICROSECONDS = 1_100_000;

    protected string $webhook = '';

    protected function setUp(): void
    {
        $this->webhook = trim((string) getenv('LOG_GOOGLE_CHAT_LIVE_WEBHOOK'));

        if ($this->webhook === '') {
            $this->markTestSkipped('Set LOG_GOOGLE_CHAT_LIVE_WEBHOOK to a scratch space webhook to run the live suite.');
        }

        parent::setUp();

        usleep(self::PACE_MICROSECONDS);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.name', 'laravel-google-chat-log live suite');
        $app['config']->set('logging.channels.google-chat.url', $this->webhook);
        $app['config']->set('logging.channels.google-chat.fallback_channel', 'live-fallback');
        $app['config']->set('logging.channels.google-chat.timeout', 10);
        $app['config']->set('logging.channels.google-chat.retries', 2);
    }

    public function test_a_message_is_delivered(): void
    {
        $fallback = $this->fakeFallback();

        $this->send($this->record('Live suite: delivery check'));

        $this->assertSame([], $this->safeRecords($fallback), 'Google Chat did not accept the message.');
    }

    public function test_an_oversized_message_is_accepted_after_trimming(): void
    {
        $fallback = $this->fakeFallback();

        // About 40 KB of mixed single and multibyte text, well over 32,000 bytes.
        $message = 'Live suite: oversized message '.str_repeat('Payload ünïcödé 支付 🚨 ', 1500);
        $this->assertGreaterThan(32000, strlen($message));

        $record = $this->record($message);
        $body = GoogleChatMessage::fromRecord($record)->toArray();
        $this->assertLessThanOrEqual(PayloadBudget::DEFAULT_BYTES, PayloadBudget::size($body));

        $this->send($record);

        $this->assertSame([], $this->safeRecords($fallback), 'Google Chat rejected the trimmed message.');
    }

    public function test_an_invalid_token_is_diagnosed_without_leaking_secrets(): void
    {
        $invalid = $this->withInvalidToken($this->webhook);
        config(['logging.channels.google-chat.url' => $invalid, 'logging.channels.google-chat.retries' => 0]);

        $fallback = $this->fakeFallback();
        $this->send($this->record('Live suite: this message must be rejected'));

        $records = $this->safeRecords($fallback);
        $this->assertCount(1, $records, 'The rejected delivery was not reported to the fallback channel.');
        $this->assertGreaterThanOrEqual(400, $records[0]['context']['status'] ?? 0);
        $this->assertLessThan(500, $records[0]['context']['status'] ?? 0);

        usleep(self::PACE_MICROSECONDS);

        $exitCode = Artisan::call('google-chat-log:test');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertMatchesRegularExpression('/Unauthorized|Forbidden|invalid|Not found/i', $output);
        $this->assertSecretsAbsent($output.json_encode($records));
    }

    protected function send(LogRecord $record): void
    {
        $handler = new GoogleChatHandler(Level::Debug);
        $handler->setFormatter(new LineFormatter(null, 'Y-m-d H:i:s', true, true, true));
        $handler->handle($record);
    }

    protected function record(string $message): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'live',
            level: Level::Error,
            message: $message,
            context: ['suite' => 'live', 'php' => PHP_VERSION],
        );
    }

    protected function fakeFallback(): FakeLogger
    {
        $fallback = new FakeLogger;
        Log::shouldReceive('channel')->with('live-fallback')->andReturn($fallback);

        return $fallback;
    }

    /**
     * The fallback records, checked for secrets before they can reach an
     * assertion message or the CI log.
     *
     * @return array<int, array{level: mixed, message: string, context: array<mixed>}>
     */
    protected function safeRecords(FakeLogger $fallback): array
    {
        $this->assertSecretsAbsent((string) json_encode($fallback->records));

        return $fallback->records;
    }

    /**
     * Assert no webhook secret (real or substituted) appears in the text.
     * Uses assertTrue so a failure never prints the text or the secret.
     */
    protected function assertSecretsAbsent(string $text): void
    {
        foreach ([$this->webhook, $this->withInvalidToken($this->webhook), ...$this->secrets()] as $secret) {
            $this->assertTrue(
                $secret === '' || ! str_contains($text, $secret),
                'Output contains a webhook secret.'
            );
        }
    }

    /**
     * @return array<int, string>
     */
    protected function secrets(): array
    {
        parse_str((string) parse_url($this->webhook, PHP_URL_QUERY), $query);

        return array_values(array_filter([
            (string) ($query['token'] ?? ''),
            'live-suite-invalid-token',
        ]));
    }

    protected function withInvalidToken(string $url): string
    {
        return (string) preg_replace('/([?&]token=)[^&]+/', '$1live-suite-invalid-token', $url);
    }
}
