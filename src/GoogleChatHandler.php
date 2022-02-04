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
        Http::post($this->getWebhookUrl(), $this->getRequestBody($record));
    }

    /**
     * Get the webhook url.
     *
     * @return mixed
     *
     * @throws \Exception
     */
    protected function getWebhookUrl()
    {
        $url = config('logging.channels.google-chat.url');
        if (!$url) {
            throw new Exception('Google chat webhook url is not configured.');
        }

        return $url;
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
            'text' => $this->notifyUserId() . substr($record['formatted'], 0, 4096),
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
                Logger::DEBUG => '#000000',
                Logger::INFO => '#48d62f',
                Logger::NOTICE => '#00aeff',
                Logger::WARNING => '#ffc400',
                Logger::ERROR => '#ff1100',
                Logger::CRITICAL => '#ff1100',
                Logger::ALERT => '#ff1100',
                Logger::EMERGENCY => '#ff1100',
            ][$record['level']] ?? '#ff1100';

        return "<b><font color='{$color}'>{$record['level_name']}</font></b> "
            . config('app.env')
            . ' [' . config('app.url') . "]<br>[{$record['datetime']}] ";
    }

    /**
     * Get the text string for notifying the configured user id.
     *
     * @return string
     */
    protected function notifyUserId(): string
    {
        return ($userId = config('logging.channels.google-chat.notify_user_id')) ? "<users/$userId>" : '';
    }
}
