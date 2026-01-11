<?php

declare(strict_types=1);

namespace Mata\Tests\Helpers;

use Mata\Core\NullDatabase;
use Mata\Services\Notification\NotificationManager;
use Monolog\Logger;

/**
 * Factory for creating test instances of NotificationManager.
 *
 * Since NotificationManager is declared final, we cannot mock or extend it.
 * This factory creates real instances with minimal test dependencies.
 */
class NotificationManagerFactory
{
    /**
     * Create a NotificationManager instance for testing.
     *
     * Uses NullDatabase to avoid real database dependencies
     */
    public static function create(Logger $logger): NotificationManager
    {
        $nullDb = new NullDatabase($logger);

        return new NotificationManager($nullDb, $logger);
    }
}
