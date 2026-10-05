<?php

declare(strict_types=1);

namespace Enigma\Support;

use Enigma\ChannelConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Posts a payload to a single webhook url, retrying 429 and 5xx responses.
 */
class Sender
{
    public function __construct(
        protected ?float $timeout = null,
        protected ?float $connectTimeout = null,
        protected int $retries = 0,
        protected int $retryDelay = 500,
        protected int $retryMaxDelay = 10000,
    ) {}

    public static function fromConfig(ChannelConfig $config): self
    {
        return new self(
            $config->timeout(),
            $config->connectTimeout(),
            $config->retries(),
            $config->retryDelay(),
            $config->retryMaxDelay(),
        );
    }

    /**
     * Send the payload and return the final response.
     *
     * Connection errors are not retried and are thrown to the caller.
     *
     * @param  array<string, mixed>  $body
     */
    public function send(string $url, array $body): Response
    {
        $attempt = 0;

        while (true) {
            $response = $this->request()->post($url, $body);

            if ($attempt >= $this->retries || ! $this->shouldRetry($response)) {
                return $response;
            }

            $attempt++;

            Sleep::usleep($this->delayFor($attempt, $response) * 1000);
        }
    }

    /**
     * Only rate limiting and server errors are worth retrying.
     */
    public function shouldRetry(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    /**
     * Delay in milliseconds before the given retry attempt (1-based).
     *
     * A Retry-After header wins over the exponential backoff; both are capped
     * at the configured maximum delay.
     */
    public function delayFor(int $attempt, Response $response): int
    {
        $retryAfter = $this->retryAfter($response);

        if ($retryAfter !== null) {
            return min($this->retryMaxDelay, $retryAfter);
        }

        $backoff = min($this->retryMaxDelay, $this->retryDelay * (2 ** ($attempt - 1)));
        $half = intdiv($backoff, 2);

        // "Equal jitter": half fixed, half random, so retries spread out
        // without ever collapsing to an immediate retry.
        return $half + random_int(0, $backoff - $half);
    }

    /**
     * The Retry-After header in milliseconds, or null when absent or invalid.
     */
    protected function retryAfter(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return max(0, (int) ((float) $header * 1000));
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, ($timestamp - time()) * 1000);
    }

    protected function request(): PendingRequest
    {
        $options = array_filter([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ], fn ($value) => $value !== null);

        return $options === [] ? Http::asJson() : Http::asJson()->withOptions($options);
    }
}
