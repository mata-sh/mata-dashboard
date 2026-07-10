<?php

declare(strict_types=1);

namespace Mata\Constants;

/**
 * Application availability states used by app cards.
 */
final class AppAvailabilityStatus
{
    public const ONLINE = 'online';
    public const OFFLINE = 'offline';
    public const MAINTENANCE = 'maintenance';
    public const PROTECTED = 'protected';

    /**
     * Prevent instantiation - this is a constants-only class.
     */
    private function __construct() {}
}
