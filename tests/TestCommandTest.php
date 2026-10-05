<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class TestCommandTest extends TestCase
{
    protected const URL = 'https://chat.googleapis.com/v1/spaces/AAA/messages?key=SECRET-KEY&token=SECRET-TOKEN';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.channels.google-chat.url', self::URL);
    }

    public function test_it_reports_a_successful_delivery(): void
    {
        Http::fake();

        $this->artisan('google-chat-log:test')
            ->expectsOutputToContain('spaces/AAA/messages?key=***&token=***')
            ->expectsOutputToContain('Delivered')
            ->doesntExpectOutputToContain('SECRET')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->data()['text'], 'Test message from php artisan google-chat-log:test')
            && $request->data()['cardsV2'][0]['card']['header']['title'] === 'ERROR: Test message from php artisan google-chat-log:test');
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function failures(): array
    {
        return [
            'forbidden' => [403, 'incoming webhooks are probably disabled by your Workspace admin'],
            'not found' => [404, 'the webhook or the space was deleted'],
            'rate limited' => [429, 'Rate limited'],
            'server error' => [503, 'server error'],
        ];
    }

    #[DataProvider('failures')]
    public function test_it_diagnoses_failures(int $status, string $diagnosis): void
    {
        Http::fake(['*' => Http::response('', $status)]);
        config(['logging.channels.google-chat.retries' => 3]);

        $this->artisan('google-chat-log:test')
            ->expectsOutputToContain($diagnosis)
            ->assertFailed();

        // Retries are disabled so the first response is what gets diagnosed.
        Http::assertSentCount(1);
    }

    public function test_it_reports_connection_errors_without_secrets(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host for '.self::URL));

        $this->artisan('google-chat-log:test')
            ->expectsOutputToContain('CONNECTION FAILED')
            ->doesntExpectOutputToContain('SECRET')
            ->assertFailed();
    }

    public function test_it_uses_the_level_option(): void
    {
        Http::fake();

        $this->artisan('google-chat-log:test', ['--level' => 'warning'])->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_starts_with($request->data()['cardsV2'][0]['card']['header']['title'], 'WARNING: '));
    }

    public function test_it_rejects_an_invalid_level(): void
    {
        $this->artisan('google-chat-log:test', ['--level' => 'loud'])
            ->expectsOutputToContain('Invalid log level')
            ->assertFailed();
    }

    public function test_it_tests_another_channel(): void
    {
        Http::fake();

        config(['logging.channels.ops' => [
            'driver' => 'monolog',
            'handler' => GoogleChatHandler::class,
            'with' => ['channel' => 'ops'],
            'url' => 'https://chat.googleapis.com/v1/spaces/OPS/messages',
        ]]);

        $this->artisan('google-chat-log:test', ['--channel' => 'ops'])->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://chat.googleapis.com/v1/spaces/OPS/messages');
    }

    public function test_it_fails_for_an_unknown_channel(): void
    {
        $this->artisan('google-chat-log:test', ['--channel' => 'nope'])
            ->expectsOutputToContain('Log channel [nope] is not defined')
            ->assertFailed();
    }

    public function test_it_fails_when_no_url_is_configured(): void
    {
        config(['logging.channels.google-chat.url' => null]);

        $this->artisan('google-chat-log:test')
            ->expectsOutputToContain('No webhook url is configured')
            ->assertFailed();
    }

    public function test_it_warns_when_the_channel_is_disabled_but_still_sends(): void
    {
        Http::fake();
        config(['logging.channels.google-chat.enabled' => false]);

        $this->artisan('google-chat-log:test')
            ->expectsOutputToContain('The channel is disabled')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }
}
