<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Cron;

use Mata\Services\Cron\CronJob;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Cron\CronJob::nameFromEntryPoint
 *
 * @internal
 */
final class CronJobNameTest extends TestCase
{
    public function testEntryPointBecomesKebabCaseJobName(): void
    {
        $this->assertSame('alarms-dispatch', CronJob::nameFromEntryPoint('/app/cron/alarms_dispatch.php'));
        $this->assertSame('servers-data-fetch', CronJob::nameFromEntryPoint('cron/Servers__Data fetch.php'));
        $this->assertSame('cleanup', CronJob::nameFromEntryPoint('/app/cron/_cleanup_.php'));
    }
}
