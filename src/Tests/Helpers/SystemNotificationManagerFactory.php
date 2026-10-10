<?php

declare(strict_types=1);

namespace Mata\Tests\Helpers;

use Mata\Database\NullDatabase;
use Mata\Services\Notification\SystemNotificationManager;
use Monolog\Logger;

/**
 * Factory for creating test instances of SystemNotificationManager.
 *
 * Since SystemNotificationManager is declared final, we cannot mock or extend it.
 * This factory creates real instances with minimal test dependencies.
 */
class SystemNotificationManagerFactory
{
    /**
     * Create a SystemNotificationManager instance for testing.
     *
     * Uses NullDatabase to avoid real database dependencies
     */
    public static function create(Logger $logger): SystemNotificationManager
    {
        $nullDb = new NullDatabase($logger);

        return new SystemNotificationManager($nullDb, $logger);
    }
}
