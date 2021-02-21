<?php

namespace Enigma;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class GoogleChatHandler extends AbstractProcessingHandler
{
    /**
     * Writes the record down to the log of the implementing handler.
     *
     * @param array $record
     */
    protected function write(array $record): void
    {
        Http::post($this->getWebhookUrl(), $this->getRequestBody($record));
    }

    /**
     * Get the card content.
     *
     * @param array $record
     * @return string
     */
    public function getCardContent(array $record): string
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
     * Get the request body content.
     *
     * @param array $record
     * @return array
     */
    protected function getRequestBody(array $record): array
    {
        return [
            'text' => $record['formatted'],
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
     * Get the webhook url.
     *
     * @return mixed
     */
    protected function getWebhookUrl()
    {
        return config('logging.channels.google-chat.url');
    }
}
