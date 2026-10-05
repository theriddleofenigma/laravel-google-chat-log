<?php

declare(strict_types=1);

namespace Enigma;

/**
 * Read-only view of a single Google Chat log channel's configuration.
 *
 * Every accessor falls back to the 3.x default when a key is missing, so a
 * channel defined without the package defaults behaves exactly like v3.1.
 */
class ChannelConfig
{
    /**
     * The channel that is used when no channel name or config is given.
     */
    public const DEFAULT_CHANNEL = 'google-chat';

    /**
     * Accepted values for the "on_missing_url" option.
     */
    public const ON_MISSING_URL_MODES = ['throw', 'ignore', 'warn'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config, protected ?string $name = null) {}

    /**
     * Build the config for a channel defined under "logging.channels".
     */
    public static function forChannel(?string $name = null): self
    {
        $name ??= self::DEFAULT_CHANNEL;
        $config = config("logging.channels.{$name}");

        return new self(is_array($config) ? $config : [], $name);
    }

    /**
     * Build the config from a raw channel config array. Without a name the
     * channel is anonymous: see name().
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, ?string $name = null): self
    {
        return new self($config, $name);
    }

    /**
     * The channel name, used to look up per-channel additional-logs hooks.
     *
     * Null for a channel built from a raw config array without a name (a
     * nameless "custom" channel or Log::build()); such a channel never picks
     * up another channel's hooks or state.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * A key that identifies this channel for per-process state: its name, or
     * a hash of its config when it has none.
     */
    public function key(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        return 'config:'.md5((string) json_encode($this->config, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * Read a raw value using "dot" notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * The raw config array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * The configured webhook urls, given as an array or a comma separated string.
     *
     * @return array<int, string>
     */
    public function urls(): array
    {
        $url = $this->get('url');

        if (empty($url)) {
            return [];
        }

        $urls = is_array($url) ? $url : explode(',', (string) $url);

        return array_values(array_filter(array_map(fn ($url) => trim((string) $url), $urls)));
    }

    /**
     * Whether the channel is enabled at all. Defaults to true.
     */
    public function enabled(): bool
    {
        return $this->bool($this->get('enabled'), true);
    }

    /**
     * The environments the channel delivers in, or null for every environment.
     *
     * @return array<int, string>|null
     */
    public function environments(): ?array
    {
        $environments = $this->list($this->get('environments'));

        return $environments === [] ? null : $environments;
    }

    /**
     * Whether the channel delivers in the current application environment.
     */
    public function allowsCurrentEnvironment(): bool
    {
        $environments = $this->environments();

        return $environments === null || app()->environment($environments);
    }

    /**
     * Request timeout in seconds, or null for the Laravel HTTP client default.
     */
    public function timeout(): ?float
    {
        return $this->seconds($this->get('timeout'));
    }

    /**
     * Connect timeout in seconds, or null for the Laravel HTTP client default.
     */
    public function connectTimeout(): ?float
    {
        return $this->seconds($this->get('connect_timeout'));
    }

    /**
     * How many times a 429 or 5xx response is retried. Defaults to 0.
     */
    public function retries(): int
    {
        return max(0, $this->int($this->get('retries'), 0));
    }

    /**
     * Base retry delay in milliseconds. Defaults to 500.
     */
    public function retryDelay(): int
    {
        return max(0, $this->int($this->get('retry_delay'), 500));
    }

    /**
     * Upper bound for a single retry delay in milliseconds, including a
     * Retry-After header sent by Google. Defaults to 10000.
     */
    public function retryMaxDelay(): int
    {
        return max(0, $this->int($this->get('retry_max_delay'), 10000));
    }

    /**
     * The log channel that receives delivery failures, or null to stay silent.
     */
    public function fallbackChannel(): ?string
    {
        $channel = $this->get('fallback_channel');

        return is_string($channel) && trim($channel) !== '' ? trim($channel) : null;
    }

    /**
     * What to do when no webhook url is configured: throw, ignore or warn.
     */
    public function onMissingUrl(): string
    {
        $mode = strtolower(trim((string) $this->get('on_missing_url')));

        return in_array($mode, self::ON_MISSING_URL_MODES, true) ? $mode : 'throw';
    }

    /**
     * The invokable class-string resolving additional logs for this channel.
     */
    public function additionalLogs(): ?string
    {
        $class = $this->get('additional_logs');

        return is_string($class) && trim($class) !== '' ? trim($class) : null;
    }

    protected function bool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    protected function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    protected function seconds(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /**
     * @return array<int, string>
     */
    protected function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $value), fn ($item) => $item !== ''));
    }
}
