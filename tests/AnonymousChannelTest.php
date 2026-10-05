<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;
use Enigma\GoogleChatLogger;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Level;

/**
 * Channels built from a raw config without a name (a nameless "custom"
 * channel, or Log::build() with "with.config") must not borrow the
 * "google-chat" channel's hooks or per-process state.
 */
class AnonymousChannelTest extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.channels.unnamed', [
            'driver' => 'custom',
            'via' => GoogleChatLogger::class,
            'url' => 'https://chat.googleapis.com/v1/spaces/UNNAMED/messages',
        ]);
    }

    public function test_a_nameless_custom_channel_does_not_use_the_google_chat_hook(): void
    {
        Http::fake();

        GoogleChatHandler::$additionalLogs = fn () => ['scope' => 'global'];
        GoogleChatHandler::additionalLogsFor('google-chat', fn () => ['scope' => 'google-chat']);

        Log::channel('unnamed')->error('Unnamed');
        Log::channel('google-chat')->error('Main');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/UNNAMED/')
            && $this->lastWidgetText($request) === '<b>Scope:</b> global');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/AAA/')
            && $this->lastWidgetText($request) === '<b>Scope:</b> google-chat');
    }

    public function test_an_on_demand_channel_does_not_use_the_google_chat_hook(): void
    {
        Http::fake();

        GoogleChatHandler::additionalLogsFor('google-chat', fn () => ['scope' => 'google-chat']);

        Log::build([
            'driver' => 'monolog',
            'handler' => GoogleChatHandler::class,
            'with' => ['config' => ['url' => 'https://chat.googleapis.com/v1/spaces/BUILT/messages']],
        ])->error('On demand');

        Http::assertSent(fn (Request $request) => $this->lastWidgetText($request) === 'Running in console');
    }

    public function test_missing_url_warnings_are_tracked_per_channel(): void
    {
        Http::fake();

        $fallback = new FakeLogger;
        Log::shouldReceive('channel')->with('audit')->andReturn($fallback);

        $missing = ['url' => null, 'on_missing_url' => 'warn', 'fallback_channel' => 'audit'];
        config(['logging.channels.google-chat' => $missing + config('logging.channels.google-chat')]);

        // The google-chat channel warns first; the nameless channels must still warn.
        (new GoogleChatHandler)->handle($this->makeRecord(Level::Error));
        (new GoogleChatHandler(config: $missing + ['notify_users' => ['default' => '1']]))->handle($this->makeRecord(Level::Error));
        (new GoogleChatHandler(config: $missing + ['notify_users' => ['default' => '2']]))->handle($this->makeRecord(Level::Error));
        // Repeats stay silent.
        (new GoogleChatHandler(config: $missing + ['notify_users' => ['default' => '2']]))->handle($this->makeRecord(Level::Error));
        (new GoogleChatHandler)->handle($this->makeRecord(Level::Error));

        $this->assertCount(3, $fallback->records);
        $this->assertSame(['google-chat', '(unnamed)', '(unnamed)'], array_column(array_column($fallback->records, 'context'), 'channel'));
    }

    protected function lastWidgetText(Request $request): string
    {
        $widgets = $request->data()['cardsV2'][0]['card']['sections'][0]['widgets'];

        return end($widgets)['decoratedText']['text'];
    }
}
