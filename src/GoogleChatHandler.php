<?php

declare(strict_types=1);

namespace Enigma;

use Closure;
use Enigma\Support\Sender;
use Enigma\Support\WebhookUrl;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Throwable;

/**
 * Monolog handler that pushes formatted log records to a Google Chat space.
 */
class GoogleChatHandler extends AbstractProcessingHandler
{
    /**
     * Closure resolving extra key-value pairs to append to every message.
     *
     * The closure receives the current LogRecord and must return an array.
     * Used for every channel that has no channel-specific hook.
     *
     * @var (Closure(LogRecord): array<int|string, mixed>)|null
     */
    public static ?Closure $additionalLogs = null;

    /**
     * Channel-specific additional-logs closures, keyed by channel name.
     *
     * @var array<string, Closure(LogRecord): array<int|string, mixed>>
     */
    protected static array $channelAdditionalLogs = [];

    /**
     * Set while a record is being delivered, so a log written during delivery
     * (by a fallback channel, an HTTP listener or a hook) can never loop back
     * into a Google Chat handler.
     */
    protected static bool $delivering = false;

    /**
     * Channels that already reported a missing webhook url in this process.
     *
     * @var array<string, true>
     */
    protected static array $missingUrlWarned = [];

    /**
     * @param  string|null  $channel  Name of the "logging.channels" entry to read settings
     *                                from. Pass it through the channel's "with" array.
     *                                Defaults to "google-chat".
     * @param  array<string, mixed>|null  $config  A full channel config array, used instead of
     *                                             reading "logging.channels", e.g. for Log::build().
     */
    public function __construct(
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        protected ?string $channel = null,
        protected ?array $config = null,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * Register an additional-logs closure for a single channel. Pass null to
     * remove it. It takes precedence over the channel's "additional_logs"
     * class and over the global $additionalLogs closure.
     *
     * @param  (Closure(LogRecord): array<int|string, mixed>)|null  $callback
     */
    public static function additionalLogsFor(string $channel, ?Closure $callback): void
    {
        if ($callback === null) {
            unset(static::$channelAdditionalLogs[$channel]);

            return;
        }

        static::$channelAdditionalLogs[$channel] = $callback;
    }

    /**
     * Resolve the additional-logs callable for a channel, in order: the
     * registered closure, the "additional_logs" class, the global closure.
     */
    public static function additionalLogsResolver(ChannelConfig $config): ?callable
    {
        if (isset(static::$channelAdditionalLogs[$config->name()])) {
            return static::$channelAdditionalLogs[$config->name()];
        }

        if ($class = $config->additionalLogs()) {
            $resolver = app()->make($class);

            if (! is_callable($resolver)) {
                throw new InvalidArgumentException("The additional logs class [{$class}] must be invokable.");
            }

            return $resolver;
        }

        return static::$additionalLogs;
    }

    /**
     * Reset all static state: hooks, the delivery guard and one-time warnings.
     */
    public static function flushState(): void
    {
        static::$additionalLogs = null;
        static::$channelAdditionalLogs = [];
        static::$delivering = false;
        static::$missingUrlWarned = [];
    }

    /**
     * The configuration of the channel this handler delivers for.
     */
    public function channelConfig(): ChannelConfig
    {
        return $this->config !== null
            ? ChannelConfig::fromArray($this->config, $this->channel)
            : ChannelConfig::forChannel($this->channel);
    }

    /**
     * Skip records when the channel is disabled or outside its environments.
     */
    public function isHandling(LogRecord $record): bool
    {
        if (! parent::isHandling($record)) {
            return false;
        }

        $config = $this->channelConfig();

        return $config->enabled() && $config->allowsCurrentEnvironment();
    }

    /**
     * Write the record to every configured Google Chat webhook.
     *
     * Delivery failures never escape this method: they are reported to the
     * "fallback_channel" when one is configured and are silent otherwise.
     */
    protected function write(LogRecord $record): void
    {
        if (static::$delivering) {
            return;
        }

        static::$delivering = true;

        try {
            $config = $this->channelConfig();
            $body = GoogleChatMessage::fromRecord($record, $config)->toArray();

            foreach ($this->webhookUrls() as $url) {
                $this->deliver($url, $body, $config);
            }
        } finally {
            static::$delivering = false;
        }
    }

    /**
     * Send the body to one webhook and report any failure.
     *
     * @param  array<string, mixed>  $body
     */
    protected function deliver(string $url, array $body, ChannelConfig $config): void
    {
        try {
            $response = Sender::fromConfig($config)->send($url, $body);

            if ($response->failed()) {
                $this->reportFailure($config, 'Google Chat rejected a log message.', [
                    'webhook' => WebhookUrl::mask($url),
                    'status' => $response->status(),
                    'response' => WebhookUrl::scrub(mb_substr($response->body(), 0, 500), [$url]),
                ]);
            }
        } catch (Throwable $exception) {
            $this->reportFailure($config, 'Google Chat log delivery failed.', [
                'webhook' => WebhookUrl::mask($url),
                'exception' => $exception::class,
                'error' => WebhookUrl::scrub($exception->getMessage(), [$url]),
            ]);
        }
    }

    /**
     * Report a problem to the fallback channel, if one is configured.
     *
     * The context never contains a raw webhook url or exception object.
     *
     * @param  array<string, mixed>  $context
     */
    protected function reportFailure(ChannelConfig $config, string $message, array $context): void
    {
        $channel = $config->fallbackChannel();

        if ($channel === null) {
            return;
        }

        try {
            Log::channel($channel)->warning($message, ['channel' => $config->name()] + $context);
        } catch (Throwable) {
            // The fallback channel itself failed; there is nowhere left to report.
        }
    }

    /**
     * The list of configured webhook urls.
     *
     * @return array<int, string>
     *
     * @throws RuntimeException When no webhook url has been configured and
     *                          "on_missing_url" is "throw" (the default).
     */
    protected function webhookUrls(): array
    {
        $config = $this->channelConfig();
        $urls = $config->urls();

        if ($urls !== []) {
            return $urls;
        }

        switch ($config->onMissingUrl()) {
            case 'ignore':
                return [];

            case 'warn':
                if (! isset(static::$missingUrlWarned[$config->name()])) {
                    static::$missingUrlWarned[$config->name()] = true;

                    $this->reportFailure($config, 'Google Chat webhook url is not configured; log messages are being dropped.', []);
                }

                return [];

            default:
                throw new RuntimeException('Google Chat webhook url is not configured.');
        }
    }
}
