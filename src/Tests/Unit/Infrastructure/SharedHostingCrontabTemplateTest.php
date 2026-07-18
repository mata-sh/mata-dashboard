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
    /**
     * servers_data_fetch and servers_metrics_collect run chained in one entry so
     * collect ingests from the freshly warmed api-cache (see template comments).
     */
    private const CHAINED_COLLECTION_ENTRY = '*/5 * * * * "$PHP_BIN" "$APP_ROOT/cron/servers_data_fetch.php" >> "$APP_ROOT/logs/cron/app-servers-data-fetch.log" 2>&1 ; "$PHP_BIN" "$APP_ROOT/cron/servers_metrics_collect.php" >> "$APP_ROOT/logs/cron/app-servers-metrics-collect.log" 2>&1';

    /** @var array<string,string> */
    private const JOBS = [
        'servers_metrics_aggregate.php' => '4-59/15 * * * *',
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

        self::assertStringContainsString(self::CHAINED_COLLECTION_ENTRY, $template);
        self::assertSame(1, substr_count($template, 'cron/servers_data_fetch.php'));
        self::assertSame(1, substr_count($template, 'cron/servers_metrics_collect.php'));

        foreach (self::JOBS as $script => $schedule) {
            self::assertSame(1, substr_count($template, "cron/{$script}"));
            self::assertMatchesRegularExpression(
                '/^' . preg_quote($schedule, '/') . ' "\$PHP_BIN" "\$APP_ROOT\/cron\/' . preg_quote($script, '/') . '" >> "\$APP_ROOT\/logs\/cron\/app-[a-z-]+\.log" 2>&1$/m',
                $template,
            );
        }
    }

    public function testTemplateShipsTheSameMaintenanceJobsAsTheDockerManifest(): void
    {
        $template = $this->template();

        // 30-day delete of stale active logs across the whole logs/ tree.
        self::assertStringContainsString(
            'find "$APP_ROOT/logs" -type f -name "*.log" ! -path "*/logs/health/*" -mtime +30 -delete',
            $template,
        );

        // 30-day delete of compressed archives and numbered leftovers; audit archives are kept.
        self::assertStringContainsString(
            'find "$APP_ROOT/logs" -type f \( -name "*.log.*.gz" -o -name "*.log.[0-9]" \) ! -path "*/logs/health/*" ! -name "user-management-audit.log.*.gz" -mtime +30 -delete',
            $template,
        );

        // Weekly rotation skips empty files and uses cron-escaped date formatting.
        self::assertStringContainsString(
            'find "$APP_ROOT/logs" -type f -name "*.log" ! -path "*/logs/health/*" -size +0c -exec sh -c',
            $template,
        );
        self::assertStringContainsString('date +\%Y\%m\%d', $template);

        // The app-managed health report directory is excluded from all three log jobs.
        self::assertSame(3, substr_count($template, '! -path "*/logs/health/*"'));

        // Cache and session cleanup mirrors the Docker manifest.
        self::assertStringContainsString('find "$APP_ROOT/temp/api-cache" -name "*.json" -mtime +7 -delete', $template);
        self::assertStringContainsString('find "$APP_ROOT/temp/sessions" -name "sess_*" -mtime +1 -delete', $template);
        self::assertStringContainsString('find "$APP_ROOT/temp/templates-cache" -name "*.php" -mtime +7 -delete', $template);
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
