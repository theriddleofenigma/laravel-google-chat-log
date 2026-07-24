<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Monolog\Level;
use Monolog\LogRecord;

class AdditionalLogsTest extends TestCase
{
    public function test_it_appends_additional_string_logs_as_widgets(): void
    {
        Http::fake();

        GoogleChatHandler::$additionalLogs = fn () => ['tenant_name' => 'Acme Inc'];

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSent(function (Request $request) {
            $widgets = $request->data()['cardsV2'][0]['card']['sections'][0]['widgets'];
            $last = end($widgets);

            return $last['decoratedText']['text'] === '<b>Tenant Name:</b> Acme Inc';
        });
    }

    public function test_it_json_encodes_non_string_additional_log_values(): void
    {
        Http::fake();

        GoogleChatHandler::$additionalLogs = fn () => ['payload' => ['a' => 1]];

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSent(function (Request $request) {
            $widgets = $request->data()['cardsV2'][0]['card']['sections'][0]['widgets'];
            $last = end($widgets);

            return $last['decoratedText']['text'] === '<b>Payload:</b> {"a":1}';
        });
    }

    public function test_it_passes_the_log_record_to_the_closure(): void
    {
        Http::fake();

        $received = null;
        GoogleChatHandler::$additionalLogs = function (LogRecord $record) use (&$received) {
            $received = $record->message;

            return [];
        };

        $this->handle($this->makeRecord(Level::Error, 'Context message'));

        $this->assertSame('Context message', $received);
    }

    public function test_it_throws_when_the_closure_does_not_return_an_array(): void
    {
        Http::fake();

        GoogleChatHandler::$additionalLogs = fn () => 'not-an-array';

        $this->expectException(InvalidArgumentException::class);

        $this->handle($this->makeRecord(Level::Error));
    }
}
