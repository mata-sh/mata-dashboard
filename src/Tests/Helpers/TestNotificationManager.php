<?php

declare(strict_types=1);

namespace Watcher\Tests\Helpers;

use Monolog\Logger;
use Watcher\Core\NullDatabase;
use Watcher\Services\Foundation\NotificationManager;

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
        $nullDb = new NullDatabase();

        return new NotificationManager($nullDb, $logger);
    }
}
