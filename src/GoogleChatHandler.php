<?php

namespace Enigma;

use Exception;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class GoogleChatHandler extends AbstractProcessingHandler
{
    /**
     * Writes the record down to the log of the implementing handler.
     *
     * @param array $record
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
            'cards' => [
                [
                    'sections' => [
                        'widgets' => [
                            'textParagraph' => [
                                'text' => $this->getCardContent($record),
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
    protected function getCardContent(array $record): string
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

        return "<b><font color='{$color}'>{$record['level_name']}</font></b> "
            . config('app.env')
            . ' [' . config('app.url') . "]<br>[{$record['datetime']}] ";
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
}
