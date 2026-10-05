<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;
use Enigma\Support\PayloadBudget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;

class PayloadBudgetTest extends PackageTestCase
{
    public function test_a_one_megabyte_message_fits_the_budget(): void
    {
        $body = $this->send($this->makeRecord(Level::Error, str_repeat('a', 1024 * 1024)));

        $this->assertLessThanOrEqual(PayloadBudget::DEFAULT_BYTES, strlen($body));

        $payload = json_decode($body, true);
        $title = $payload['cardsV2'][0]['card']['header']['title'];
        $this->assertStringStartsWith('ERROR: aaa', $title);
        $this->assertStringEndsWith(PayloadBudget::MARKER, $title);
    }

    public function test_a_huge_multibyte_message_fits_without_splitting_characters(): void
    {
        $body = $this->send($this->makeRecord(Level::Error, str_repeat('支付失败🚨é', 50000)));

        $this->assertLessThanOrEqual(PayloadBudget::DEFAULT_BYTES, strlen($body));

        $payload = json_decode($body, true);
        $this->assertIsArray($payload, 'The trimmed payload must still be valid JSON.');
        $this->assertTrue(mb_check_encoding($payload['text'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($payload['cardsV2'][0]['card']['header']['title'], 'UTF-8'));
    }

    public function test_a_huge_context_fits_the_budget(): void
    {
        $context = [];
        for ($i = 0; $i < 5000; $i++) {
            $context["key_{$i}"] = str_repeat('v', 50);
        }

        $body = $this->send($this->makeRecord(Level::Error, 'Short message', $context));

        $this->assertLessThanOrEqual(PayloadBudget::DEFAULT_BYTES, strlen($body));

        // The title was small enough to be left alone.
        $payload = json_decode($body, true);
        $this->assertSame('ERROR: Short message', $payload['cardsV2'][0]['card']['header']['title']);
    }

    public function test_huge_additional_logs_are_trimmed_before_the_text(): void
    {
        GoogleChatHandler::$additionalLogs = fn () => ['dump' => str_repeat('x', 100000)];

        $body = $this->send($this->makeRecord(Level::Error, 'Readable message'));

        $this->assertLessThanOrEqual(PayloadBudget::DEFAULT_BYTES, strlen($body));

        $payload = json_decode($body, true);
        $this->assertStringContainsString('Readable message', $payload['text']);
        $this->assertStringNotContainsString(PayloadBudget::MARKER, $payload['text']);

        $widgets = $payload['cardsV2'][0]['card']['sections'][0]['widgets'];
        $this->assertStringStartsWith('<b>Dump:</b> xxx', end($widgets)['decoratedText']['text']);
        $this->assertStringEndsWith(PayloadBudget::MARKER, end($widgets)['decoratedText']['text']);

        // The env, level and time widgets are never trimmed.
        $this->assertSame('Testing [Env]', $widgets[0]['decoratedText']['text']);
    }

    public function test_mentions_survive_trimming(): void
    {
        config(['logging.channels.google-chat.notify_users.default' => '123']);

        $body = $this->send($this->makeRecord(Level::Error, str_repeat('🚨', 20000)));

        $this->assertStringStartsWith('<users/123> ', json_decode($body, true)['text']);
    }

    public function test_a_normal_record_is_not_touched(): void
    {
        $payload = ['text' => 'small', 'cardsV2' => [['card' => ['header' => ['title' => 'small']]]]];

        $this->assertSame($payload, (new PayloadBudget)->apply($payload, [['text'], ['cardsV2.0.card.header.title']]));
    }

    public function test_truncate_respects_the_encoded_byte_length(): void
    {
        $value = str_repeat('é', 100); // 6 bytes each once JSON encoded

        $truncated = PayloadBudget::truncate($value, 60);

        $this->assertLessThanOrEqual(60, PayloadBudget::encodedLength($truncated));
        $this->assertStringEndsWith(PayloadBudget::MARKER, $truncated);
        $this->assertSame(str_repeat('é', 8).PayloadBudget::MARKER, $truncated);
    }

    protected function send(LogRecord $record): string
    {
        Http::fake();

        $handler = new GoogleChatHandler($record->level);
        $handler->setFormatter(new LineFormatter(null, 'Y-m-d H:i:s', true, true, true));
        $handler->handle($record);

        $body = '';
        Http::assertSent(function (Request $request) use (&$body) {
            $body = $request->body();

            return true;
        });

        return $body;
    }
}
