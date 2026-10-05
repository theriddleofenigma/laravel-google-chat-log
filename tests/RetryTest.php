<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Monolog\Level;

class RetryTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_it_does_not_retry_by_default(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 429)->push('', 200)]);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_it_retries_a_429_then_succeeds(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 429)->push('', 200)]);
        config(['logging.channels.google-chat.retries' => 2]);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_it_retries_server_errors_up_to_the_limit(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 503)->push('', 500)->push('', 502)->push('', 200)]);
        config(['logging.channels.google-chat.retries' => 2]);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_it_does_not_retry_client_errors(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 400)->push('', 200)]);
        config(['logging.channels.google-chat.retries' => 2]);

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_it_honours_retry_after(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 429, ['Retry-After' => '3'])->push('', 200)]);
        config(['logging.channels.google-chat.retries' => 1]);

        $this->handle($this->makeRecord(Level::Error));

        Sleep::assertSequence([Sleep::usleep(3_000_000)]);
    }

    public function test_retry_after_is_capped_by_the_max_delay(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 429, ['Retry-After' => '120'])->push('', 200)]);
        config([
            'logging.channels.google-chat.retries' => 1,
            'logging.channels.google-chat.retry_max_delay' => 2000,
        ]);

        $this->handle($this->makeRecord(Level::Error));

        Sleep::assertSequence([Sleep::usleep(2_000_000)]);
    }

    public function test_backoff_grows_exponentially_with_jitter(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 500)->push('', 500)->push('', 500)->push('', 200)]);
        config([
            'logging.channels.google-chat.retries' => 3,
            'logging.channels.google-chat.retry_delay' => 1000,
        ]);

        $this->handle($this->makeRecord(Level::Error));

        $slept = [];
        Sleep::assertSlept(function ($duration) use (&$slept) {
            $slept[] = $duration->totalMilliseconds;

            return true;
        }, 3);

        // Attempt n waits between half and all of retry_delay * 2^(n-1).
        foreach ([1000, 2000, 4000] as $i => $backoff) {
            $this->assertGreaterThanOrEqual($backoff / 2, $slept[$i]);
            $this->assertLessThanOrEqual($backoff, $slept[$i]);
        }
    }

    public function test_timeouts_default_to_the_laravel_http_client_defaults(): void
    {
        $options = $this->capturedOptions();

        $this->assertSame(30, $options['timeout']);
        $this->assertSame(10, $options['connect_timeout']);
    }

    public function test_timeouts_can_be_configured(): void
    {
        config([
            'logging.channels.google-chat.timeout' => '5',
            'logging.channels.google-chat.connect_timeout' => 2,
        ]);

        $options = $this->capturedOptions();

        $this->assertEquals(5, $options['timeout']);
        $this->assertEquals(2, $options['connect_timeout']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function capturedOptions(): array
    {
        $captured = [];

        Http::fake(function (Request $request, array $options) use (&$captured) {
            $captured = $options;

            return Http::response();
        });

        $this->handle($this->makeRecord(Level::Error));

        return $captured;
    }
}
