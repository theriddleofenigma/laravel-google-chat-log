<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use RuntimeException;

class GoogleChatHandlerTest extends TestCase
{
    public function test_it_sends_the_log_record_to_the_configured_webhook(): void
    {
        Http::fake();

        $this->handle($this->makeRecord(Level::Error, 'Payment failed'));

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://chat.googleapis.com/v1/spaces/AAA/messages'
                && $body['cardsV2'][0]['card']['header']['title'] === Level::Error->getName().': Payment failed'
                && $body['cardsV2'][0]['card']['header']['subtitle'] === 'Test App'
                && str_contains($body['text'], 'Payment failed');
        });
    }

    public function test_it_builds_a_spec_compliant_cardsv2_payload(): void
    {
        Http::fake();

        $this->handle($this->makeRecord(Level::Error, 'Payment failed'));

        Http::assertSent(function (Request $request) {
            $card = $request->data()['cardsV2'][0]['card'];

            // "sections" must be a list of Section objects, not a single object.
            $this->assertArrayHasKey(0, $card['sections']);
            $this->assertSame(array_keys($card['sections']), range(0, count($card['sections']) - 1));

            $section = $card['sections'][0];
            $this->assertSame('Details', $section['header']);
            $this->assertTrue($section['collapsible']);
            $this->assertIsArray($section['widgets']);
            $this->assertArrayHasKey(0, $section['widgets']);

            return true;
        });
    }

    public function test_it_works_through_the_laravel_log_channel(): void
    {
        Http::fake();

        Log::channel('google-chat')->error('Broken via channel');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->data()['text'], 'Broken via channel'));
    }

    public function test_it_sends_to_every_comma_separated_webhook(): void
    {
        Http::fake();

        config([
            'logging.channels.google-chat.url' => 'https://chat.googleapis.com/one , https://chat.googleapis.com/two',
        ]);

        $this->handle($this->makeRecord(Level::Warning));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://chat.googleapis.com/one');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://chat.googleapis.com/two');
    }

    public function test_it_sends_to_every_webhook_given_as_an_array(): void
    {
        Http::fake();

        config([
            'logging.channels.google-chat.url' => [
                'https://chat.googleapis.com/one',
                'https://chat.googleapis.com/two',
            ],
        ]);

        $this->handle($this->makeRecord(Level::Info));

        Http::assertSentCount(2);
    }

    public function test_it_throws_when_no_webhook_url_is_configured(): void
    {
        Http::fake();

        config(['logging.channels.google-chat.url' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Chat webhook url is not configured.');

        $this->handle($this->makeRecord(Level::Error));
    }

    public function test_it_colours_the_level_widget(): void
    {
        Http::fake();

        $this->handle($this->makeRecord(Level::Info));

        Http::assertSent(function (Request $request) {
            $widgets = $request->data()['cardsV2'][0]['card']['sections'][0]['widgets'];

            return $widgets[1]['decoratedText']['text'] === "<font color='#48d62f'>".Level::Info->getName().'</font>';
        });
    }

    public function test_it_truncates_the_text_to_the_google_chat_limit(): void
    {
        Http::fake();

        $this->handle($this->makeRecord(Level::Error, str_repeat('a', 5000)));

        Http::assertSent(fn (Request $request) => mb_strlen($request->data()['text']) === 4096);
    }
}
