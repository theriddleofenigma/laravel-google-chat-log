<?php

declare(strict_types=1);

namespace Enigma;

use Closure;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use RuntimeException;

/**
 * Monolog handler that pushes formatted log records to a Google Chat space.
 */
class GoogleChatHandler extends AbstractProcessingHandler
{
    /**
     * Closure resolving extra key-value pairs to append to every message.
     *
     * The closure receives the current LogRecord and must return an array.
     *
     * @var (Closure(LogRecord): array<int|string, mixed>)|null
     */
    public static ?Closure $additionalLogs = null;

    /**
     * Write the record to every configured Google Chat webhook.
     */
    protected function write(LogRecord $record): void
    {
        $body = GoogleChatMessage::fromRecord($record)->toArray();

        foreach ($this->webhookUrls() as $url) {
            Http::post($url, $body);
        }
    }

    /**
     * The list of configured webhook urls.
     *
     * @return array<int, string>
     *
     * @throws RuntimeException When no webhook url has been configured.
     */
    protected function webhookUrls(): array
    {
        $url = config('logging.channels.google-chat.url');

        if (empty($url)) {
            throw new RuntimeException('Google Chat webhook url is not configured.');
        }

        $urls = is_array($url) ? $url : explode(',', $url);

        return array_values(array_filter(array_map('trim', $urls)));
    }
}
