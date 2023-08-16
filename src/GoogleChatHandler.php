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
     * Writes the record down to the log of the implementing handler.
     *
     * @param array $record
     *
     * @throws Exception
     */
    protected function write(array $record): void
    {
        foreach ($this->getWebhookUrl() as $url) {
            $response = Http::post($url, $this->getRequestBody($record));
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
     * @param array $recordArr
     * @return array
     */
    protected function getRequestBody(array $recordArr): array
    {
        $recordArr['formatted'] = substr($recordArr['formatted'], 34);
        return [
            'text' => substr($this->getNotifiableText($recordArr['level'] ?? '') . $recordArr['formatted'], 0, 3800),
            'cardsV2' => [
                [
                    'cardId' => 'info-card-id',
                    'card' => [
                        'header' => [
                            'title' => "{$recordArr['level_name']}: {$recordArr['message']}",
                            'subtitle' => config('app.url'),
                        ],
                        'sections' => [
                            'header' => 'Details',
                            'collapsible' => true,
                            'uncollapsibleWidgetsCount' => 1,
                            'widgets' => [
                                $this->cardWidget(ucwords(config('app.env') ?? '') . ' [Env]', 'BOOKMARK'),
                                $this->cardWidget($this->getLevelContent($recordArr), 'TICKET'),
                                $this->cardWidget($recordArr['datetime'], 'CLOCK'),
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
     * @param array $recordArr
     * @return string
     */
    protected function getLevelContent(array $recordArr): string
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
        ][$recordArr['level']] ?? '#ff1100';

        return "<font color='{$color}'>{$recordArr['level_name']}</font>";
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
        $otherIds = implode(array_map(
            function ($userId) use (&$allUsers) {
                if (strtolower($userId) === 'all') {
                    $allUsers = '<users/all> ';
                    return '';
                }

                return "<users/$userId> ";
            },
            array_unique(
                explode(',', $userIds)
            )
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
}
