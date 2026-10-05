<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;
use Enigma\GoogleChatLogger;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class CustomDriverTest extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.channels.google-chat.notify_users.default', '111');

        // Two copies of the same definition that differ only in url and mentions:
        // the setup that silently shared settings with the monolog driver.
        foreach (['ops' => 'OPS', 'billing' => 'BILLING'] as $name => $space) {
            $app['config']->set("logging.channels.{$name}", [
                'driver' => 'custom',
                'via' => GoogleChatLogger::class,
                'name' => $name,
                'url' => "https://chat.googleapis.com/v1/spaces/{$space}/messages",
                'level' => 'error',
                'notify_users' => ['default' => $space === 'OPS' ? '222' : '333'],
            ]);
        }
    }

    public function test_channels_built_by_the_factory_do_not_share_settings(): void
    {
        Http::fake();

        Log::channel('ops')->error('Ops failure');
        Log::channel('billing')->error('Billing failure');
        Log::channel('google-chat')->error('Main failure');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://chat.googleapis.com/v1/spaces/OPS/messages'
            && str_starts_with($request->data()['text'], '<users/222> ')
            && str_contains($request->data()['text'], 'Ops failure'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://chat.googleapis.com/v1/spaces/BILLING/messages'
            && str_starts_with($request->data()['text'], '<users/333> ')
            && str_contains($request->data()['text'], 'Billing failure'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://chat.googleapis.com/v1/spaces/AAA/messages'
            && str_starts_with($request->data()['text'], '<users/111> ')
            && str_contains($request->data()['text'], 'Main failure'));
    }

    public function test_the_channel_level_is_honoured(): void
    {
        Http::fake();

        Log::channel('ops')->warning('Below the level');

        Http::assertNothingSent();
    }

    public function test_the_action_level_buffers_until_triggered(): void
    {
        Http::fake();
        config(['logging.channels.ops.level' => 'debug', 'logging.channels.ops.action_level' => 'critical']);

        $logger = (new GoogleChatLogger)(config('logging.channels.ops'));
        $this->assertInstanceOf(FingersCrossedHandler::class, $logger->getHandlers()[0]);

        $logger->error('Buffered');
        Http::assertNothingSent();

        $logger->critical('Trigger');
        Http::assertSentCount(2);
    }

    public function test_the_default_formatter_matches_the_monolog_driver(): void
    {
        $logger = (new GoogleChatLogger)(config('logging.channels.ops'));
        $handler = $logger->getHandlers()[0];

        $this->assertInstanceOf(GoogleChatHandler::class, $handler);
        $this->assertEquals(new LineFormatter(null, 'Y-m-d H:i:s', true, true, true), $handler->getFormatter());
        $this->assertSame('ops', $logger->getName());
    }

    public function test_the_monolog_channel_name_falls_back_to_the_environment(): void
    {
        $config = config('logging.channels.ops');
        unset($config['name']);

        $this->assertSame('testing', (new GoogleChatLogger)($config)->getName());
    }

    public function test_processors_are_applied(): void
    {
        Http::fake();
        config(['logging.channels.ops.processors' => [TagProcessor::class]]);

        Log::channel('ops')->error('With processor');

        Http::assertSent(fn (Request $request) => str_contains($request->data()['text'], '"tagged":true'));
    }

    public function test_additional_logs_can_be_registered_by_name(): void
    {
        Http::fake();

        GoogleChatHandler::$additionalLogs = fn () => ['scope' => 'global'];
        GoogleChatHandler::additionalLogsFor('ops', fn () => ['scope' => 'ops']);

        Log::channel('ops')->error('Ops');
        Log::channel('billing')->error('Billing');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/OPS/')
            && $this->lastWidgetText($request) === '<b>Scope:</b> ops');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/BILLING/')
            && $this->lastWidgetText($request) === '<b>Scope:</b> global');
    }

    public function test_the_test_command_reads_the_channel_config(): void
    {
        Http::fake();

        $this->artisan('google-chat-log:test', ['--channel' => 'billing'])
            ->expectsOutputToContain('spaces/BILLING/messages')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_starts_with($request->data()['text'], '<users/333> '));
    }

    public function test_the_test_command_flags_a_url_the_handler_ignores(): void
    {
        Http::fake();

        config(['logging.channels.copied' => [
            'driver' => 'monolog',
            'handler' => GoogleChatHandler::class,
            'url' => 'https://chat.googleapis.com/v1/spaces/COPIED/messages',
        ]]);

        $this->artisan('google-chat-log:test', ['--channel' => 'copied'])
            ->expectsOutputToContain('reads the [google-chat] channel config')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://chat.googleapis.com/v1/spaces/AAA/messages');
    }

    public function test_the_golden_payload_is_unchanged_through_the_factory(): void
    {
        Http::fake();

        // The golden fixture is the plain default config, without mentions.
        $config = config('logging.channels.google-chat');
        $config['notify_users']['default'] = null;

        $record = $this->makeRecord(Level::Error, 'Payment failed for order #42', ['order_id' => 42, 'gateway' => 'stripe']);

        (new GoogleChatLogger)($config)->getHandlers()[0]->handle($record);

        Http::assertSent(function (Request $request) {
            $this->assertSame(file_get_contents(__DIR__.'/fixtures/golden-default.json'), $request->body());

            return true;
        });
    }

    protected function lastWidgetText(Request $request): string
    {
        $widgets = $request->data()['cardsV2'][0]['card']['sections'][0]['widgets'];

        return end($widgets)['decoratedText']['text'];
    }
}

class TagProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: ['tagged' => true]);
    }
}
