<?php

declare(strict_types=1);

namespace Mata\Constants;

/**
 * Application availability states used by app cards.
 */
enum AppAvailabilityStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Maintenance = 'maintenance';
    case Protected = 'protected';
}
