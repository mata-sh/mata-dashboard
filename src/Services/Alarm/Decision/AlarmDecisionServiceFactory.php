<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Decision;

use Mata\Config\Dto\OpaAlarmDecisionConfig;
use Mata\Config\Env;
use RuntimeException;

final class AlarmDecisionServiceFactory
{
    public static function fromEnv(): AlarmDecisionServiceInterface
    {
        $driver = strtolower(trim((string) Env::get('ALARM_DECISION_DRIVER', 'opa')));

        return match ($driver) {
            'opa' => new OpaAlarmDecisionService(OpaAlarmDecisionConfig::fromEnv()),
            'builtin' => new BuiltinAlarmDecisionService(max(1, (int) Env::get('ALARM_DECISION_BATCH_LIMIT', '100'))),
            default => throw new RuntimeException(
                sprintf('Invalid ALARM_DECISION_DRIVER "%s". Valid options: opa, builtin.', $driver)
            ),
        };
    }
}
