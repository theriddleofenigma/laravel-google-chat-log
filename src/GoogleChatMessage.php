<?php

declare(strict_types=1);

namespace Enigma;

use InvalidArgumentException;
use JsonException;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Builds the Google Chat "cardsV2" request payload for a single log record.
 */
class GoogleChatMessage
{
    /**
     * The maximum number of characters Google Chat accepts in the text field.
     */
    protected const MAX_TEXT_LENGTH = 4096;

    public function __construct(protected LogRecord $record) {}

    public static function fromRecord(LogRecord $record): self
    {
        return new self($record);
    }

    /**
     * Build the full request body for the Google Chat webhook.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text(),
            'cardsV2' => [
                [
                    'cardId' => 'info-card-id',
                    'card' => [
                        'header' => [
                            'title' => "{$this->record->level->getName()}: {$this->record->message}",
                            'subtitle' => config('app.name'),
                        ],
                        'sections' => [
                            [
                                'header' => 'Details',
                                'collapsible' => true,
                                'uncollapsibleWidgetsCount' => 3,
                                'widgets' => $this->widgets(),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The plain-text portion of the message, capped at Google Chat's limit.
     */
    protected function text(): string
    {
        return mb_substr(
            $this->notifiableText().$this->record->formatted,
            0,
            self::MAX_TEXT_LENGTH
        );
    }

    /**
     * The decorated widgets rendered inside the details section.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function widgets(): array
    {
        return [
            $this->widget(ucwords((string) (config('app.env') ?: 'NA')).' [Env]', 'BOOKMARK'),
            $this->widget($this->levelContent($this->record->level), 'TICKET'),
            $this->widget($this->record->datetime->format('Y-m-d H:i:s T'), 'CLOCK'),
            $this->widget($this->requestUrl(), 'BUS'),
            ...$this->customWidgets(),
        ];
    }

    /**
     * The current request url, or a console placeholder when off the web.
     */
    protected function requestUrl(): string
    {
        if (app()->runningInConsole()) {
            return 'Running in console';
        }

        return request()->fullUrl();
    }

    /**
     * A single decorated-text widget.
     *
     * @return array<string, mixed>
     */
    public function widget(string $text, string $icon): array
    {
        return [
            'decoratedText' => [
                'startIcon' => [
                    'knownIcon' => $icon,
                ],
                'text' => $text,
            ],
        ];
    }

    /**
     * The colour-coded level label.
     */
    protected function levelContent(Level $level): string
    {
        $color = match ($level) {
            Level::Warning => '#ffc400',
            Level::Notice => '#00aeff',
            Level::Info => '#48d62f',
            Level::Debug => '#000000',
            // Default matches emergency, alert, critical and error.
            default => '#ff1100',
        };

        return "<font color='{$color}'>{$level->getName()}</font>";
    }

    /**
     * The @mention prefix for the configured users of the record's level.
     */
    protected function notifiableText(): string
    {
        $level = strtolower($this->record->level->getName());

        $levelUserIds = trim((string) $this->config("notify_users.{$level}"));
        $defaultUserIds = trim((string) $this->config('notify_users.default'));

        if ($defaultUserIds !== '' && $levelUserIds !== '') {
            $levelUserIds = ",{$levelUserIds}";
        }

        return $this->constructNotifiableText($defaultUserIds.$levelUserIds);
    }

    /**
     * Turn a comma separated list of user ids into Google Chat mention tags.
     */
    protected function constructNotifiableText(string $userIds): string
    {
        if ($userIds === '') {
            return '';
        }

        $ids = array_unique(array_filter(array_map('trim', explode(',', $userIds))));

        $allUsers = '';
        $otherIds = implode(array_map(function ($userId) use (&$allUsers) {
            if (strtolower($userId) === 'all') {
                $allUsers = '<users/all> ';

                return '';
            }

            return "<users/{$userId}> ";
        }, $ids));

        return $allUsers.$otherIds;
    }

    /**
     * Build the widgets for any user supplied additional logs.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function customWidgets(): array
    {
        $additionalLogs = GoogleChatHandler::$additionalLogs;
        if (! $additionalLogs) {
            return [];
        }

        $logs = $additionalLogs($this->record);
        if (! is_array($logs)) {
            throw new InvalidArgumentException('Data returned from the additional logs closure must be an array.');
        }

        $widgets = [];
        foreach ($logs as $key => $value) {
            if ($value !== null && ! is_string($value)) {
                try {
                    $value = json_encode($value, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException(
                        "Additional log value for key [{$key}] must be a string or JSON encodable value.",
                        0,
                        $exception
                    );
                }
            }

            if (! is_numeric($key)) {
                $key = ucwords(str_replace('_', ' ', (string) $key));
                $value = "<b>{$key}:</b> {$value}";
            }

            $widgets[] = $this->widget((string) $value, 'DESCRIPTION');
        }

        return $widgets;
    }

    /**
     * Read a value from the google-chat log channel configuration.
     */
    protected function config(string $key): mixed
    {
        return config("logging.channels.google-chat.{$key}");
    }
}
