<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;

/**
 * Base class for the 3.2+ tests: also resets the handler's static state
 * (per-channel hooks, the delivery guard and one-time warnings).
 *
 * TestCase itself stays as shipped in 3.1, so the original tests run unchanged.
 */
abstract class PackageTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GoogleChatHandler::flushState();
    }

    protected function tearDown(): void
    {
        GoogleChatHandler::flushState();

        parent::tearDown();
    }
}
