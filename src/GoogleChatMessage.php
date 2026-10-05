<?php

declare(strict_types=1);

namespace Enigma;

use Enigma\Support\PayloadBudget;
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

    /**
     * Index of the first widget in the details section that may be trimmed by
     * the payload budget (the request url and any additional logs).
     */
    protected const FIRST_TRIMMABLE_WIDGET = 3;

    /**
     * @param  ChannelConfig|null  $channelConfig  The channel to read settings from.
     *                                             Defaults to the "google-chat" channel.
     */
    public function __construct(protected LogRecord $record, protected ?ChannelConfig $channelConfig = null) {}

    public static function fromRecord(LogRecord $record, ?ChannelConfig $channelConfig = null): self
    {
        return new self($record, $channelConfig);
    }

    /**
     * Build the full request body for the Google Chat webhook.
     *
     * The body is trimmed only when it exceeds the payload byte budget, so
     * messages within Google's size limit are sent exactly as built.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = $this->payload();

        // Floors (encoded bytes) kept per field on the first trimming pass:
        // widgets, text, title. See PayloadBudget::apply().
        return (new PayloadBudget)->apply($payload, $this->trimmableFields($payload), [256, 4000, 1000]);
    }

    /**
     * The untrimmed request body.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
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
     * Dot paths of the string fields the payload budget may trim, grouped in
     * trimming priority order: context widgets, then text, then the title.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<int, string>>
     */
    protected function trimmableFields(array $payload): array
    {
        $widgets = [];

        foreach ($payload['cardsV2'][0]['card']['sections'] ?? [] as $s => $section) {
            foreach ($section['widgets'] ?? [] as $w => $widget) {
                if ($s === 0 && $w < self::FIRST_TRIMMABLE_WIDGET) {
                    continue;
                }

                if (isset($widget['decoratedText']['text'])) {
                    $widgets[] = "cardsV2.0.card.sections.{$s}.widgets.{$w}.decoratedText.text";
                }
            }
        }

        return [
            $widgets,
            ['text'],
            ['cardsV2.0.card.header.title'],
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
        $additionalLogs = GoogleChatHandler::additionalLogsResolver($this->channelConfig());
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
     * Read a value from the log channel configuration.
     */
    protected function config(string $key): mixed
    {
        return $this->channelConfig()->get($key);
    }

    /**
     * The channel config this message is built for.
     */
    protected function channelConfig(): ChannelConfig
    {
        return $this->channelConfig ?? ChannelConfig::forChannel();
    }
}
