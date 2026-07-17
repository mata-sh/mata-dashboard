<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Infrastructure;

use Mata\Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class SharedHostingCrontabTemplateTest extends TestCase
{
    /** @var array<string,string> */
    private const JOBS = [
        'servers_data_fetch.php' => '2-59/5 * * * *',
        'servers_metrics_collect.php' => '*/5 * * * *',
        'servers_metrics_aggregate.php' => '*/15 * * * *',
        'alarms_evaluate_critical.php' => '* * * * *',
        'alarms_evaluate_non_critical.php' => '*/5 * * * *',
        'alarms_dispatch.php' => '* * * * *',
        'retention_cleanup.php' => '25 2 * * *',
    ];

    public function testTemplateRunsEveryCronScriptWithoutADockerEnvironmentFile(): void
    {
        $template = $this->template();

        self::assertStringContainsString('APP_ROOT=/home/ACCOUNT/mata-dashboard', $template);
        self::assertStringContainsString('PHP_BIN=/usr/local/bin/php', $template);
        self::assertStringNotContainsString('CRON_ENV_FILE=', $template);

        foreach (self::JOBS as $script => $schedule) {
            self::assertSame(1, substr_count($template, "cron/{$script}"));
            self::assertMatchesRegularExpression(
                '/^' . preg_quote($schedule, '/') . ' "\$PHP_BIN" "\$APP_ROOT\/cron\/' . preg_quote($script, '/') . '" >> "\$APP_ROOT\/logs\/cron\/app-[a-z-]+\.log" 2>&1$/m',
                $template,
            );
        }
    }

    private function template(): string
    {
        $template = file_get_contents($this->projectRoot() . '/cron/crontab.shared-hosting.template');

        self::assertNotFalse($template);

        return $template;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
