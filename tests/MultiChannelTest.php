<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;

class MultiChannelTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.channels.google-chat.notify_users.default', '111');
        $app['config']->set('logging.channels.google-chat-ops', [
            'driver' => 'monolog',
            'handler' => GoogleChatHandler::class,
            'with' => ['channel' => 'google-chat-ops'],
            'url' => 'https://chat.googleapis.com/v1/spaces/OPS/messages?key=k&token=t',
            'level' => 'critical',
            'notify_users' => ['default' => '999'],
        ]);
    }

    public function test_each_channel_uses_its_own_webhook_and_mentions(): void
    {
        Http::fake();

        Log::channel('google-chat')->critical('Main channel');
        Log::channel('google-chat-ops')->critical('Ops channel');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://chat.googleapis.com/v1/spaces/AAA/messages'
            && str_starts_with($request->data()['text'], '<users/111> ')
            && str_contains($request->data()['text'], 'Main channel'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://chat.googleapis.com/v1/spaces/OPS/')
            && str_starts_with($request->data()['text'], '<users/999> ')
            && str_contains($request->data()['text'], 'Ops channel'));
    }

    public function test_each_channel_uses_its_own_level(): void
    {
        Http::fake();

        Log::channel('google-chat-ops')->error('Below the ops level');

        Http::assertNothingSent();
    }

    public function test_a_config_array_can_be_passed_directly(): void
    {
        Http::fake();

        Log::build([
            'driver' => 'monolog',
            'handler' => GoogleChatHandler::class,
            'with' => ['config' => ['url' => 'https://chat.googleapis.com/v1/spaces/BUILT/messages']],
        ])->error('On demand');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://chat.googleapis.com/v1/spaces/BUILT/messages'
            && ! str_contains($request->data()['text'], '<users/111>'));
    }

    public function test_additional_logs_can_be_registered_per_channel(): void
    {
        Http::fake();

        GoogleChatHandler::$additionalLogs = fn () => ['scope' => 'global'];
        GoogleChatHandler::additionalLogsFor('google-chat-ops', fn () => ['scope' => 'ops']);

        Log::channel('google-chat')->critical('Main');
        Log::channel('google-chat-ops')->critical('Ops');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/AAA/')
            && $this->lastWidgetText($request) === '<b>Scope:</b> global');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/OPS/')
            && $this->lastWidgetText($request) === '<b>Scope:</b> ops');
    }

    public function test_additional_logs_can_be_an_invokable_class(): void
    {
        Http::fake();

        GoogleChatHandler::$additionalLogs = fn () => ['scope' => 'global'];
        config(['logging.channels.google-chat-ops.additional_logs' => ChatExtras::class]);

        Log::channel('google-chat-ops')->critical('Ops');

        Http::assertSent(fn (Request $request) => $this->lastWidgetText($request) === '<b>Level Name:</b> CRITICAL');
    }

    public function test_the_registered_closure_wins_over_the_invokable_class(): void
    {
        Http::fake();

        config(['logging.channels.google-chat-ops.additional_logs' => ChatExtras::class]);
        GoogleChatHandler::additionalLogsFor('google-chat-ops', fn () => ['scope' => 'registered']);

        Log::channel('google-chat-ops')->critical('Ops');

        Http::assertSent(fn (Request $request) => $this->lastWidgetText($request) === '<b>Scope:</b> registered');
    }

    public function test_a_registered_closure_can_be_removed(): void
    {
        Http::fake();

        GoogleChatHandler::additionalLogsFor('google-chat', fn () => ['scope' => 'registered']);
        GoogleChatHandler::additionalLogsFor('google-chat', null);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSent(fn (Request $request) => $this->lastWidgetText($request) === 'Running in console');
    }

    protected function lastWidgetText(Request $request): string
    {
        $widgets = $request->data()['cardsV2'][0]['card']['sections'][0]['widgets'];

        return end($widgets)['decoratedText']['text'];
    }
}

class ChatExtras
{
    /**
     * @return array<string, string>
     */
    public function __invoke(LogRecord $record): array
    {
        return ['level_name' => $record->level->getName()];
    }
}
