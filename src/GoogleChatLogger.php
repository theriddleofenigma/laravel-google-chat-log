<?php

declare(strict_types=1);

namespace Enigma;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger;

/**
 * Factory for a "custom" log driver channel:
 *
 *     'google-chat-ops' => [
 *         'driver' => 'custom',
 *         'via' => \Enigma\GoogleChatLogger::class,
 *         'name' => 'google-chat-ops',
 *         'url' => env('LOG_GOOGLE_CHAT_OPS_WEBHOOK_URL'),
 *         'level' => 'critical',
 *     ],
 *
 * Laravel passes the full channel config to the factory, so every option is
 * read from the channel itself; nothing is shared with "google-chat". The
 * logger is built the same way Laravel builds a "monolog" driver channel:
 * same default formatter, and the "formatter", "processors" and
 * "action_level" keys work as documented for Laravel channels.
 */
class GoogleChatLogger
{
    /**
     * Laravel's default log date format (LogManager::$dateFormat).
     */
    protected const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $name = isset($config['name']) && is_string($config['name']) && $config['name'] !== ''
            ? $config['name']
            : null;

        $handler = new GoogleChatHandler(
            Logger::toMonologLevel($config['level'] ?? 'debug'),
            (bool) ($config['bubble'] ?? true),
            $name,
            $config,
        );

        $this->applyFormatter($handler, $config);

        return new Logger(
            $name ?? $this->fallbackChannelName(),
            [$this->wrap($handler, $config)],
            $this->processors($config),
        );
    }

    /**
     * Apply the formatter the way LogManager::prepareHandler() does.
     *
     * @param  array<string, mixed>  $config
     */
    protected function applyFormatter(GoogleChatHandler $handler, array $config): void
    {
        if (! isset($config['formatter'])) {
            $handler->setFormatter(new LineFormatter(null, self::DATE_FORMAT, true, true, true));
        } elseif ($config['formatter'] !== 'default') {
            $handler->setFormatter(app()->make($config['formatter'], $config['formatter_with'] ?? []));
        }
    }

    /**
     * Wrap the handler in a FingersCrossedHandler when "action_level" is set.
     *
     * @param  array<string, mixed>  $config
     */
    protected function wrap(GoogleChatHandler $handler, array $config): HandlerInterface
    {
        if (! isset($config['action_level'])) {
            return $handler;
        }

        return new FingersCrossedHandler(
            $handler,
            Logger::toMonologLevel($config['action_level']),
            0,
            true,
            $config['stop_buffering'] ?? true,
        );
    }

    /**
     * Resolve the "processors" key the way LogManager does.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, callable>
     */
    protected function processors(array $config): array
    {
        return array_map(
            fn ($processor) => app()->make($processor['processor'] ?? $processor, $processor['with'] ?? []),
            array_values($config['processors'] ?? [])
        );
    }

    /**
     * The channel name Laravel gives a monolog channel without a "name" key.
     */
    protected function fallbackChannelName(): string
    {
        return app()->bound('env') ? app()->environment() : 'production';
    }
}
