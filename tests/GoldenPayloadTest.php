<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Byte-for-byte snapshots of the request body produced by v3.1.0.
 *
 * These fixtures must never change during 3.x: every new feature is opt-in,
 * so a record sent with the default configuration has to stay identical.
 */
class GoldenPayloadTest extends PackageTestCase
{
    public function test_the_default_payload_is_unchanged(): void
    {
        $this->assertGolden('golden-default.json', $this->makeRecord(
            Level::Error,
            'Payment failed for order #42',
            ['order_id' => 42, 'gateway' => 'stripe'],
        ));
    }

    public function test_the_payload_with_mentions_and_additional_logs_is_unchanged(): void
    {
        config([
            'logging.channels.google-chat.notify_users.default' => '111, all',
            'logging.channels.google-chat.notify_users.critical' => '222',
        ]);

        GoogleChatHandler::$additionalLogs = fn () => [
            'tenant_name' => 'Acme Inc',
            'flags' => ['beta' => true, 'tier' => 3],
            'plain value',
        ];

        $this->assertGolden('golden-extended.json', $this->makeRecord(
            Level::Critical,
            'Ünïcödé failure – 支付失败 🚨 <b>html</b> "quoted" /path',
            ['user' => ['id' => 7, 'email' => 'jane@example.com'], 'url' => 'https://example.com/a?b=c'],
        ));
    }

    protected function assertGolden(string $fixture, LogRecord $record): void
    {
        Http::fake();

        // Same formatter the Laravel LogManager attaches to monolog channels.
        $handler = new GoogleChatHandler($record->level);
        $handler->setFormatter(new LineFormatter(null, 'Y-m-d H:i:s', true, true, true));
        $handler->handle($record);

        $path = __DIR__.'/fixtures/'.$fixture;

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($path) {
            $this->assertSame(file_get_contents($path), $request->body());

            return true;
        });
    }
}
