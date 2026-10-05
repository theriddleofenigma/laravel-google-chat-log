<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Collects log calls made to a fallback channel.
 */
class FakeLogger extends AbstractLogger
{
    /**
     * @var array<int, array{level: mixed, message: string, context: array<mixed>}>
     */
    public array $records = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
