<?php

namespace Enigma;

use Exception;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class GoogleChatHandler extends AbstractProcessingHandler
{

    /**
     * Google chat channel webhook
     * @var array
     */
    private array $webhookUrls;

    /**
     * Optional user specific notifications configured per log level
     * @var array
     */
    private array $userNotificationConfig;

    /**
     * Additional logs closure.
     *
     * @var \Closure|null
     */
    public static \Closure|null $additionalLogs = null;


    /**
     * @param string|array $url
     * @param array $notify_users
     * @param int|string|Level $level
     * @param bool $bubble
     */
    public function __construct(
        string|array     $url,
        array            $notify_users = [],
        int|string|Level $level = Level::Debug,
        bool             $bubble = true
    ){
        parent::__construct($level, $bubble);

        $this->webhookUrls = $this->parseWebhookUrl($url);
        $this->userNotificationConfig = $notify_users;
    }

    /**
     * Parse the webhook URL config value into an array, since multiple comma-separated URLs are supported
     *
     * @param string|array $url
     * @return array
     */
    protected function parseWebhookUrl(string|array $url): array
    {
        if (is_array($url)) {
            return $url;
        }

        return array_map(function ($each) {
            return trim($each);
        }, explode(',', $url));
    }

    /**
     * Writes the record down to the log of the implementing handler.
     *
     * @param LogRecord $record
     *
     * @throws \Exception
     */
    protected function write(LogRecord $record): void
    {
        foreach ($this->webhookUrls as $url) {
            Http::post($url, $this->getRequestBody($record));
        }
    }

    /**
     * Get the request body content.
     *
     * @param LogRecord $record
     * @return array
     */
    protected function getRequestBody(LogRecord $record): array
    {
        return [
            'text' => substr($this->getNotifiableText($record->level->value ?? '') . $record->formatted, 0, 4096),
            'cardsV2' => [
                [
                    'cardId' => 'info-card-id',
                    'card' => [
                        'header' => [
                            'title' => "{$record->level->name}: {$record->message}",
                            'subtitle' => config('app.name'),
                        ],
                        'sections' => [
                            'header' => 'Details',
                            'collapsible' => true,
                            'uncollapsibleWidgetsCount' => 3,
                            'widgets' => [
                                $this->cardWidget(ucwords(config('app.env') ?: 'NA') . ' [Env]', 'BOOKMARK'),
                                $this->cardWidget($this->getLevelContent($record), 'TICKET'),
                                $this->cardWidget($record->datetime, 'CLOCK'),
                                $this->cardWidget(request()->url(), 'BUS'),
                                ...$this->getCustomLogs(),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get the card content.
     *
     * @param LogRecord $record
     * @return string
     */
    protected function getLevelContent(LogRecord $record): string
    {
        $color = [
            Level::Emergency->value => '#ff1100',
            Level::Alert->value => '#ff1100',
            Level::Critical->value => '#ff1100',
            Level::Error->value => '#ff1100',
            Level::Warning->value => '#ffc400',
            Level::Notice->value => '#00aeff',
            Level::Info->value => '#48d62f',
            Level::Debug->value => '#000000',
        ][$record->level->value] ?? '#ff1100';

        return "<font color='{$color}'>{$record->level->name}</font>";
    }

    /**
     * Get the text string for notifying the configured user id.
     *
     * @param $level
     * @return string
     */
    protected function getNotifiableText($level): string
    {
        $levelBasedUserIds = [
            Level::Emergency->value => $this->userNotificationConfig['emergency'] ?? '',
            Level::Alert->value => $this->userNotificationConfig['alert'] ?? '',
            Level::Critical->value => $this->userNotificationConfig['critical'] ?? '',
            Level::Error->value => $this->userNotificationConfig['error'] ?? '',
            Level::Warning->value => $this->userNotificationConfig['warning'] ?? '',
            Level::Notice->value => $this->userNotificationConfig['notice'] ?? '',
            Level::Info->value => $this->userNotificationConfig['info'] ?? '',
            Level::Debug->value => $this->userNotificationConfig['debug'] ?? '',
        ][$level] ?? '';

        $levelBasedUserIds = trim($levelBasedUserIds);
        $userIds = $this->userNotificationConfig['default'] ?? '';

        if ($userIds && $levelBasedUserIds) {
            $levelBasedUserIds = ",$levelBasedUserIds";
        }

        return $this->constructNotifiableText(trim($userIds) . $levelBasedUserIds);
    }

    /**
     * Get the notifiable text for the given userIds String.
     *
     * @param $userIds
     * @return string
     */
    protected function constructNotifiableText($userIds): string
    {
        if (!$userIds) {
            return '';
        }

        $allUsers = '';
        $otherIds = implode(array_map(function ($userId) use (&$allUsers) {
            if (strtolower($userId) === 'all') {
                $allUsers = '<users/all> ';
                return '';
            }

            return "<users/$userId> ";
        }, array_unique(
                explode(',', $userIds))
        ));

        return $allUsers . $otherIds;
    }

    /**
     * Card widget content.
     *
     * @return array[]
     */
    public function cardWidget(string $text, string $icon): array
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
     * Get the custom logs.
     *
     * @return array
     * @throws Exception
     */
    public function getCustomLogs(): array
    {
        $additionalLogs = GoogleChatHandler::$additionalLogs;
        if (!$additionalLogs) {
            return [];
        }

        $additionalLogs = $additionalLogs(request());
        if (!is_array($additionalLogs)) {
            throw new Exception('Data returned from the additional Log must be an array.');
        }

        $logs = [];
        foreach ($additionalLogs as $key => $value) {
            if ($value && !is_string($value)) {
                try {
                    $value = json_encode($value);
                } catch (\Throwable $throwable) {
                    throw new Exception("Additional log key-value should be a string for key[{$key}]. For logging objects, json or array, please stringify by doing json encode or serialize on the value.", 0, $throwable);
                }
            }

            if (!is_numeric($key)) {
                $key = ucwords(str_replace('_', ' ', $key));
                $value = "<b>{$key}:</b> $value";
            }
            $logs[] = $this->cardWidget($value, 'CONFIRMATION_NUMBER_ICON');
        }

        return $logs;
    }
}
