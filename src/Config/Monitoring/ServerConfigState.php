<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

final class ServerConfigState
{
    /** @param array<string,mixed> $config */
    public static function isDisabled(array $config): bool
    {
        return ($config['disabled'] ?? false) === true;
    }
}
