<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Level;

class NotifyUsersTest extends TestCase
{
    public function test_it_prepends_the_default_mention_for_every_level(): void
    {
        Http::fake();

        config(['logging.channels.google-chat.notify_users.default' => '123']);

        $this->handle($this->makeRecord(Level::Info));

        Http::assertSent(fn (Request $request) => str_starts_with($request->data()['text'], '<users/123> '));
    }

    public function test_it_combines_default_and_level_specific_mentions(): void
    {
        Http::fake();

        config([
            'logging.channels.google-chat.notify_users.default' => '123',
            'logging.channels.google-chat.notify_users.error' => '456',
        ]);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSent(function (Request $request) {
            $text = $request->data()['text'];

            return str_contains($text, '<users/123> ') && str_contains($text, '<users/456> ');
        });
    }

    public function test_it_does_not_add_level_mentions_from_other_levels(): void
    {
        Http::fake();

        config(['logging.channels.google-chat.notify_users.error' => '456']);

        $this->handle($this->makeRecord(Level::Info));

        Http::assertSent(fn (Request $request) => ! str_contains($request->data()['text'], '<users/456>'));
    }

    public function test_it_supports_mentioning_everyone(): void
    {
        Http::fake();

        config(['logging.channels.google-chat.notify_users.default' => 'all']);

        $this->handle($this->makeRecord(Level::Critical));

        Http::assertSent(fn (Request $request) => str_starts_with($request->data()['text'], '<users/all> '));
    }

    public function test_it_deduplicates_and_trims_user_ids(): void
    {
        Http::fake();

        config(['logging.channels.google-chat.notify_users.default' => '123, 123 , 456']);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSent(function (Request $request) {
            $text = $request->data()['text'];

            return substr_count($text, '<users/123>') === 1
                && substr_count($text, '<users/456>') === 1;
        });
    }
}
