<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use FilesystemIterator;
use Mata\Config\Dto\AlarmLimitsConfig;
use Mata\Config\Dto\MailConfig;
use Mata\Core\App;
use Mata\Core\Auth;
use Mata\Http\Request;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Alarm\Dto\AlarmLimit;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Mata\Views\SettingsRenderer;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use Twig\Environment;

/**
 * @covers \Mata\Views\SettingsRenderer
 *
 * @internal
 */
final class SettingsRendererTest extends TestCase
{
    public function testListsExpectedAndAdditionalConfigurationFiles(): void
    {
        $appPath = sys_get_temp_dir() . '/mata-settings-' . bin2hex(random_bytes(8));
        $configPath = $appPath . '/config';
        mkdir($configPath, 0777, true);
        file_put_contents($configPath . '/config.env', 'APP_ENV=demo');
        file_put_contents($configPath . '/limits.json', '{}');
        file_put_contents($configPath . '/thresholds.json', '{}');
        file_put_contents($configPath . '/events.json', '{}');
        file_put_contents($configPath . '/zz-extra.json', '{}');
        file_put_contents($configPath . '/aa-extra.env', 'A=1');

        try {
            $method = new ReflectionMethod(SettingsRenderer::class, 'buildConfigFilesMetadata');
            $files = $method->invoke($this->createRenderer($appPath), $configPath, $configPath . '/users.json');
            $filesByName = array_column($files, null, 'name');

            $this->assertSame(
                [
                    'config.env',
                    'db.config.env',
                    'mail.config.env',
                    'limits.json',
                    'servers.json',
                    'ttl.json',
                    'thresholds.json',
                    'notification_rules.json',
                    'data_retention.json',
                    'intervals.json',
                    'probes.json',
                    'menu.json',
                    'users.json',
                    'aa-extra.env',
                    'events.json',
                    'zz-extra.json',
                ],
                array_column($files, 'name')
            );
            $this->assertTrue($filesByName['limits.json']['exists']);
            $this->assertTrue($filesByName['thresholds.json']['exists']);
            $this->assertFalse($filesByName['notification_rules.json']['exists']);
            $this->assertFalse($filesByName['probes.json']['exists']);
            $this->assertFalse($filesByName['menu.json']['exists']);
            $this->assertTrue($filesByName['events.json']['exists']);
            $this->assertFalse($filesByName['users.json']['exists']);
        } finally {
            $this->deleteDirectory($appPath);
        }
    }

    public function testListsExternalUsersFileAtItsConfiguredPath(): void
    {
        $appPath = sys_get_temp_dir() . '/mata-settings-' . bin2hex(random_bytes(8));
        $configPath = $appPath . '/config';
        $usersFilePath = sys_get_temp_dir() . '/mata-users-' . bin2hex(random_bytes(8)) . '.json';
        mkdir($configPath, 0777, true);
        file_put_contents($usersFilePath, '{}');

        try {
            $method = new ReflectionMethod(SettingsRenderer::class, 'buildConfigFilesMetadata');
            $files = $method->invoke($this->createRenderer($appPath), $configPath, $usersFilePath);
            $filesByName = array_column($files, null, 'name');

            $this->assertSame($usersFilePath, $filesByName['users.json']['path']);
            $this->assertTrue($filesByName['users.json']['exists']);
        } finally {
            unlink($usersFilePath);
            $this->deleteDirectory($appPath);
        }
    }

    public function testSummarizesMailConfigWithoutExposingThePassword(): void
    {
        $password = 'sup3rSecretPassw0rd';
        $mail = $this->createMailConfig("smtp://dev@xarc.dev:{$password}@mail.example.com:587");

        $method = new ReflectionMethod(SettingsRenderer::class, 'summarizeMailConfig');
        $summary = $method->invoke($this->createRenderer('/tmp/mata-mail'), $mail);

        $this->assertTrue($summary['configured']);
        $this->assertSame('smtp', $summary['transport']['scheme']);
        $this->assertSame('mail.example.com', $summary['transport']['host']);
        $this->assertSame(587, $summary['transport']['port']);
        $this->assertSame('dev@xarc.dev', $summary['transport']['username']);
        $this->assertTrue($summary['transport']['has_password']);
        $this->assertStringNotContainsString($password, json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function testMasksTheDsnUserWhenItCarriesTheCredential(): void
    {
        $apiKey = 'SG-abcdefghijklmnop';
        $mail = $this->createMailConfig("sendgrid+api://{$apiKey}@default");

        $method = new ReflectionMethod(SettingsRenderer::class, 'summarizeMailConfig');
        $summary = $method->invoke($this->createRenderer('/tmp/mata-mail'), $mail);

        $this->assertFalse($summary['transport']['has_password']);
        $this->assertSame('mnop', substr($summary['transport']['username'], -4));
        $this->assertStringNotContainsString($apiKey, json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function testReportsMissingMailTransport(): void
    {
        $method = new ReflectionMethod(SettingsRenderer::class, 'summarizeMailConfig');
        $summary = $method->invoke($this->createRenderer('/tmp/mata-mail'), $this->createMailConfig(null));

        $this->assertTrue($summary['configured']);
        $this->assertFalse($summary['transport']['configured']);
    }

    public function testFlattensIntervalsIncludingDisabledCooldowns(): void
    {
        $method = new ReflectionMethod(SettingsRenderer::class, 'summarizeIntervalsConfig');
        $summary = $method->invoke($this->createRenderer('/tmp/mata-intervals'), [
            'events' => ['min_event_interval_minutes' => ['_default' => 60, 'app_unreachable' => 0]],
            'alarms' => ['min_alarm_interval_minutes' => ['_default' => 1440]],
            'bogus' => 'not-an-array',
        ]);

        $this->assertSame(['alarms', 'events'], array_column($summary, 'section'));

        $events = $summary[1]['groups'][0];
        $this->assertSame('min_event_interval_minutes', $events['group']);
        $this->assertSame(['_default', 'app_unreachable'], array_column($events['entries'], 'key'));
        $this->assertTrue($events['entries'][0]['is_default']);
        $this->assertSame('no cooldown', $events['entries'][1]['formatted']);
    }

    public function testFormatsProbeValuesByUnitSuffix(): void
    {
        $method = new ReflectionMethod(SettingsRenderer::class, 'summarizeProbesConfig');
        $summary = $method->invoke($this->createRenderer('/tmp/mata-probes'), [
            'timeout_seconds' => 5,
            'backoff_base_ms' => 500,
            'max_retries' => 1,
            'nested' => ['ignored' => true],
        ]);

        $formatted = array_column($summary, 'formatted', 'key');
        $this->assertSame(['backoff_base_ms', 'max_retries', 'timeout_seconds'], array_keys($formatted));
        $this->assertSame('500 ms', $formatted['backoff_base_ms']);
        $this->assertSame('1', $formatted['max_retries']);
        $this->assertSame('5 seconds', $formatted['timeout_seconds']);
    }

    public function testSummarizesAlarmLimitsWithChannelsAndOverrides(): void
    {
        $limits = new AlarmLimitsConfig(
            new AlarmLimit(20, 120, 500),
            ['webhook' => new AlarmLimit(60, 500, 5000), 'email' => new AlarmLimit(30, 200, 1000)],
            ['alpha' => ['email' => new AlarmLimit(5, 10, 20)]],
        );

        $method = new ReflectionMethod(SettingsRenderer::class, 'summarizeAlarmLimits');
        $summary = $method->invoke($this->createRenderer('/tmp/mata-limits'), $limits);

        $this->assertSame(['per_minute' => 20, 'per_hour' => 120, 'per_day' => 500], $summary['default']);
        $this->assertSame(['email', 'webhook'], array_column($summary['channels'], 'channel'));
        $this->assertSame('alpha', $summary['overrides'][0]['server']);
        $this->assertSame(5, $summary['overrides'][0]['channels'][0]['limits']['per_minute']);
    }

    public function testSummarizesAbsentAlarmLimits(): void
    {
        $method = new ReflectionMethod(SettingsRenderer::class, 'summarizeAlarmLimits');
        $summary = $method->invoke($this->createRenderer('/tmp/mata-limits'), null);

        $this->assertNull($summary['default']);
        $this->assertSame([], $summary['channels']);
        $this->assertSame([], $summary['overrides']);
    }

    private function createMailConfig(?string $dsn): MailConfig
    {
        return new MailConfig(
            dsn: $dsn,
            fromEmail: 'noreply@example.com',
            fromName: 'MATA',
            alarmRecipients: ['ops@example.com'],
            testRecipients: [],
            devRecipients: [],
            mailerApp: 'sendgrid',
            mailerDev: 'phpmailer',
            charset: 'utf-8',
            wordWrap: 75,
            dashboardUrl: null,
        );
    }

    private function createRenderer(string $appPath): SettingsRenderer
    {
        $paths = $this->createStub(PathResolver::class);
        $paths->appPath = $appPath;
        $paths->method('toRelativePath')->willReturnCallback(
            static fn (string $path): string => str_replace($appPath . '/', '', $path)
        );

        $app = new App($this->createStub(Auth::class), ApplicationConfigFactory::create(), $paths, new Request());

        return new SettingsRenderer(
            $this->createStub(Environment::class),
            $this->createStub(Logger::class),
            new Tools($appPath),
            $app,
            $this->createStub(Endpoints::class)
        );
    }

    private function deleteDirectory(string $path): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
