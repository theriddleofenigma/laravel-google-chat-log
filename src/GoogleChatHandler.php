<?php

namespace Enigma;

use Exception;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;

class GoogleChatHandler extends AbstractProcessingHandler
{
    /**
     * Additional logs closure.
     *
     * @var \Closure|null
     */
    public static \Closure|null $additionalLogs = null;

    /**
     * Writes the record down to the log of the implementing handler.
     *
     * @param LogRecord $record
     *
     * @throws \Exception
     */
    protected function write(array $record): void
    {
        foreach ($this->getWebhookUrl() as $url) {
            Http::post($url, $this->getRequestBody($record));
        }
    }

    /**
     * Get the webhook url.
     *
     * @return array
     *
     * @throws \Exception
     */
    protected function getWebhookUrl(): array
    {
        $url = config('logging.channels.google-chat.url');
        if (!$url) {
            throw new Exception('Google chat webhook url is not configured.');
        }

        if (is_array($url)) {
            return $url;
        }

        return array_map(function ($each) {
            return trim($each);
        }, explode(',', $url));
    }

    /**
     * Get the request body content.
     *
     * @param array $record
     * @return array
     */
    protected function getRequestBody(array $record): array
    {
        return [
            'text' => substr($this->getNotifiableText($record['level'] ?? '') . $record['formatted'], 0, 4096),
            'cardsV2' => [
                [
                    'cardId' => 'info-card-id',
                    'card' => [
                        'header' => [
                            'title' => "{$record['level_name']}: {$record['message']}",
                            'subtitle' => config('app.name'),
                        ],
                        'sections' => [
                            'header' => 'Details',
                            'collapsible' => true,
                            'uncollapsibleWidgetsCount' => 3,
                            'widgets' => [
                                $this->cardWidget(ucwords(config('app.env') ?: 'NA') . ' [Env]', 'BOOKMARK'),
                                $this->cardWidget($this->getLevelContent($record), 'TICKET'),
                                $this->cardWidget($record['datetime'], 'CLOCK'),
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
     * @param array $record
     * @return string
     */
    protected function getLevelContent(array $record): string
    {
        $color = [
            Logger::EMERGENCY => '#ff1100',
            Logger::ALERT => '#ff1100',
            Logger::CRITICAL => '#ff1100',
            Logger::ERROR => '#ff1100',
            Logger::WARNING => '#ffc400',
            Logger::NOTICE => '#00aeff',
            Logger::INFO => '#48d62f',
            Logger::DEBUG => '#000000',
        ][$record['level']] ?? '#ff1100';

        return "<font color='{$color}'>{$record['level_name']}</font>";
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
            Logger::EMERGENCY => config('logging.channels.google-chat.notify_users.emergency'),
            Logger::ALERT => config('logging.channels.google-chat.notify_users.alert'),
            Logger::CRITICAL => config('logging.channels.google-chat.notify_users.critical'),
            Logger::ERROR => config('logging.channels.google-chat.notify_users.error'),
            Logger::WARNING => config('logging.channels.google-chat.notify_users.warning'),
            Logger::NOTICE => config('logging.channels.google-chat.notify_users.notice'),
            Logger::INFO => config('logging.channels.google-chat.notify_users.info'),
            Logger::DEBUG => config('logging.channels.google-chat.notify_users.debug'),
        ][$level] ?? '';

        $levelBasedUserIds = trim($levelBasedUserIds);
        if (($userIds = config('logging.channels.google-chat.notify_users.default')) && $levelBasedUserIds) {
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
