<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\Support\WebhookUrl;
use PHPUnit\Framework\TestCase;

class WebhookUrlTest extends TestCase
{
    protected const URL = 'https://chat.googleapis.com/v1/spaces/AAAA1234/messages?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI&token=abcdEFGH1234';

    public function test_it_masks_a_webhook_url(): void
    {
        $this->assertSame('spaces/AAAA1234/messages?key=***&token=***', WebhookUrl::mask(self::URL));
    }

    public function test_it_masks_urls_that_are_not_chat_webhooks(): void
    {
        $this->assertSame('example.com/***?secret=***', WebhookUrl::mask('https://example.com/hook?secret=abc'));
    }

    public function test_it_extracts_the_space_id(): void
    {
        $this->assertSame('AAAA1234', WebhookUrl::spaceId(self::URL));
        $this->assertNull(WebhookUrl::spaceId('https://example.com/hook'));
    }

    public function test_it_scrubs_urls_and_secrets_from_text(): void
    {
        $text = 'cURL error 28 for '.self::URL.' (token abcdEFGH1234) json: '.str_replace('/', '\/', self::URL);

        $scrubbed = WebhookUrl::scrub($text, [self::URL]);

        $this->assertStringNotContainsString('AIzaSy', $scrubbed);
        $this->assertStringNotContainsString('abcdEFGH1234', $scrubbed);
        $this->assertStringContainsString('spaces/AAAA1234/messages?key=***&token=***', $scrubbed);
    }

    public function test_it_scrubs_unknown_urls(): void
    {
        $scrubbed = WebhookUrl::scrub('failed: https://chat.googleapis.com/v1/spaces/X/messages?key=abc&token=def');

        $this->assertSame('failed: spaces/X/messages?key=***&token=***', $scrubbed);
    }
}
