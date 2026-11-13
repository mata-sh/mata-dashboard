<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Cli;

use Mata\Cli\Command\Config\ConfigValidateCommand;
use Mata\Config\ConfigSource;
use Mata\Config\Validation\ConfigProblem;
use Mata\Config\Validation\ConfigValidationReport;
use Mata\Config\Validation\ConfigValidator;
use Mata\Services\Status\StatusLevel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 *
 * @coversNothing
 */
final class ConfigValidateCommandTest extends TestCase
{
    private string $path;

    /** @var array<string,mixed> */
    private array $env;

    /** @var array<string,mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->env = $_ENV;
        $this->server = $_SERVER;
        $_ENV = $_SERVER = [];
        $this->path = sys_get_temp_dir() . '/mata-config-command-' . bin2hex(random_bytes(4));
        mkdir($this->path);
        file_put_contents($this->path . '/config.env', "DOMAIN=example.com\n");
        file_put_contents($this->path . '/db.config.env', "DB_HOST=localhost\nDB_USERNAME=mata\nDB_PASSWORD=never-print-this\nDB_DATABASE=mata\nDB_PORT=3306\n");
        file_put_contents($this->path . '/mail.config.env', "SYM_MAILER_DSN=null://null\nSYM_FROM_EMAIL=monitor@example.com\n");
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        $_SERVER = $this->server;
        array_map('unlink', glob($this->path . '/*') ?: []);
        rmdir($this->path);
    }

    public function testValidMergedEnvExitsZero(): void
    {
        $tester = $this->runCommand(false);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Valid.', $tester->getDisplay());
    }

    public function testProcessOnlyErrorIsVisibleInHumanAndJsonAndExitsTwo(): void
    {
        foreach ([false, true] as $json) {
            $_ENV = $_SERVER = [];
            $_ENV['DASHBOARD_SERVER_COLUMNS'] = '0';
            $tester = $this->runCommand($json);
            $this->assertSame(2, $tester->getStatusCode());
            $display = $tester->getDisplay();
            $this->assertStringNotContainsString('never-print-this', $display);
            if ($json) {
                $report = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame([], $report['problems'][0]['files']);
                $this->assertSame([['kind' => 'process', 'file' => null, 'variable' => 'DASHBOARD_SERVER_COLUMNS']], $report['problems'][0]['sources']);
            } else {
                $this->assertStringContainsString('[process:DASHBOARD_SERVER_COLUMNS]', $display);
                $this->assertStringContainsString('Invalid configuration', $display);
            }
        }
    }

    public function testMissingFileAndVariableRemainVisibleWhenSelectingAnotherEnvFile(): void
    {
        unlink($this->path . '/mail.config.env');
        foreach ([false, true] as $json) {
            $_ENV = $_SERVER = [];
            $tester = $this->runCommand($json);
            $this->assertSame(2, $tester->getStatusCode());
            if ($json) {
                $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
                $problems = array_column($report['problems'], null, 'type');
                $this->assertSame(['mail.config.env'], $problems['config.file.missing']['files']);
                $this->assertSame([['kind' => 'missing', 'file' => null, 'variable' => 'SYM_MAILER_DSN']], $problems['config.mail.dsn.missing']['sources']);
            } else {
                $this->assertStringContainsString('mail.config.env is missing', $tester->getDisplay());
                $this->assertStringContainsString('[missing:SYM_MAILER_DSN]', $tester->getDisplay());
            }
        }
    }

    public function testMultiFileWarningPrintsOnceWithAllSources(): void
    {
        file_put_contents($this->path . '/config.env', "DOMAIN=example.com\nDB_HOST=0\n");
        file_put_contents($this->path . '/db.config.env', "DB_PASSWORD=0\nDB_DATABASE=mata\nDB_PORT=3306\n");
        $_ENV['DB_USERNAME'] = '0';
        $tester = $this->runCommand(false);
        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertSame(1, substr_count($display, 'Database configuration incomplete'));
        $this->assertStringContainsString('[config.env:DB_HOST', $display);
        $this->assertStringContainsString('db.config.env:DB_PASSWORD', $display);
        $this->assertStringContainsString('process:DB_USERNAME', $display);
    }

    public function testUnattributedProblemIsNotLostByFileSummary(): void
    {
        $report = new ConfigValidationReport($this->path, ['config.env' => true], [
            new ConfigProblem(StatusLevel::Error, 'unexpected', 'Unexpected loader failure'),
            new ConfigProblem(StatusLevel::Warning, 'missing', 'Missing input', [ConfigSource::missing('DOMAIN')]),
        ]);
        $output = new BufferedOutput();
        $command = new ConfigValidateCommand(new ConfigValidator(), $this->path);
        (new ReflectionMethod($command, 'writeHuman'))->invoke($command, $output, $report);
        $display = $output->fetch();
        $this->assertSame(1, substr_count($display, '[unattributed] Unexpected loader failure'));
        $this->assertStringContainsString('[missing:DOMAIN] Missing input', $display);
        $this->assertStringContainsString('Invalid configuration', $display);
    }

    private function runCommand(bool $json): CommandTester
    {
        $tester = new CommandTester(new ConfigValidateCommand(new ConfigValidator(), $this->path));
        $tester->execute(['file' => 'config.env', '--json' => $json]);

        return $tester;
    }
}
