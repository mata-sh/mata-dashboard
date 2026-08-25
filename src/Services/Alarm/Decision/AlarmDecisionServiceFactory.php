<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Decision;

use Mata\Config\Dto\AlarmDecisionConfig;
use RuntimeException;

final class AlarmDecisionServiceFactory
{
    public static function fromConfig(AlarmDecisionConfig $config): AlarmDecisionServiceInterface
    {
        return match ($config->driver) {
            'opa' => new OpaAlarmDecisionService($config->opa, $config->batchLimit),
            'builtin' => new BuiltinAlarmDecisionService($config->batchLimit),
            default => throw new RuntimeException(
                sprintf('Invalid ALARM_DECISION_DRIVER "%s". Valid options: opa, builtin.', $config->driver)
            ),
        };
    }
}
