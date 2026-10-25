<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\ConfigSource;
use Mata\Config\Validation\ConfigProblem;
use Mata\Config\Validation\ConfigValidationReport;
use Mata\Config\Validation\InstanceConfigValidator;
use Mata\Services\Status\StatusLevel;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class InstanceConfigValidatorTest extends TestCase
{
    private string $configPath;

    /** @var array<string,mixed> */
    private array $env;

    /** @var array<string,mixed> */
    private array $server;

    protected function setUp(): void
    {
        // Dotenv writes the template values into $_ENV and $_SERVER.
        $this->env = $_ENV;
        $this->server = $_SERVER;
        $this->configPath = sys_get_temp_dir() . '/mata-config-validate-' . bin2hex(random_bytes(4));
        mkdir($this->configPath);
        foreach (glob(dirname(__DIR__, 4) . '/config.example/*.example') ?: [] as $template) {
            copy($template, $this->configPath . '/' . basename($template, '.example'));
        }
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        $_SERVER = $this->server;
        // policy/ files first, so the directory is empty when the top level removes it.
        foreach ([...glob($this->configPath . '/policy/*') ?: [], ...glob($this->configPath . '/*') ?: []] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->configPath);
    }

    public function testMovedVariableUsesActualFileAndAnyEnvSelectionKeepsIt(): void
    {
        $this->writeMinimalEnv();
        file_put_contents($this->configPath . '/mail.config.env', "SYM_MAILER_DSN=null://null\nDASHBOARD_SERVER_COLUMNS=0\n");

        foreach (['config.env', 'db.config.env', 'mail.config.env'] as $selected) {
            $_ENV = $_SERVER = [];
            $report = (new InstanceConfigValidator())->validate($this->configPath, $selected);
            $this->assertSame(StatusLevel::Error, $report->level());
            $this->assertCount(1, $report->problems);
            $problem = $report->problems[0];
            $this->assertSame(['mail.config.env'], $problem->files());
            $this->assertEquals([ConfigSource::file('mail.config.env', 'DASHBOARD_SERVER_COLUMNS')], $problem->sources);
        }
    }

    public function testMalformedEnvUsesParserFileRatherThanConventionalGuess(): void
    {
        $this->writeMinimalEnv();
        file_put_contents($this->configPath . '/db.config.env', "INVALID NAME=value\n");

        $report = (new InstanceConfigValidator())->validate($this->configPath, 'mail.config.env');

        $this->assertSame(StatusLevel::Error, $report->level());
        $this->assertCount(1, $report->problems);
        $this->assertSame(['db.config.env'], $report->problems[0]->files());
        $this->assertEquals([ConfigSource::file('db.config.env')], $report->problems[0]->sources);
    }

    public function testCommentOnlyServersRemainWarningRatherThanDroppedServerError(): void
    {
        $this->writeMinimalEnv();
        file_put_contents($this->configPath . '/servers.json', '{"_comment":"No nodes yet"}');
        file_put_contents($this->configPath . '/servers.secrets.json', '{}');

        $report = (new InstanceConfigValidator())->validate($this->configPath, 'servers.json');

        $this->assertSame(StatusLevel::Warning, $report->level());
        $this->assertSame(StatusLevel::Warning, $report->fileStatus('servers.json'));
    }

    public function testAggregateWarningKeepsFileAndProcessSources(): void
    {
        $this->writeMinimalEnv();
        $_ENV['DB_USERNAME'] = '0';
        file_put_contents($this->configPath . '/config.env', "DOMAIN=example.com\nDB_HOST=0\n");
        file_put_contents($this->configPath . '/mail.config.env', "SYM_MAILER_DSN=null://null\nSYM_FROM_EMAIL=monitor@example.com\nDB_PASSWORD=0\n");
        file_put_contents($this->configPath . '/db.config.env', "DB_DATABASE=mata\nDB_PORT=3306\n");

        $report = (new InstanceConfigValidator())->validate($this->configPath, 'db.config.env');

        $this->assertSame(StatusLevel::Warning, $report->level());
        $this->assertCount(1, $report->problems);
        $this->assertSame(['config.env', 'mail.config.env'], $report->problems[0]->files());
        $this->assertEquals([
            ConfigSource::file('config.env', 'DB_HOST'),
            ConfigSource::process('DB_USERNAME'),
            ConfigSource::file('mail.config.env', 'DB_PASSWORD'),
        ], $report->problems[0]->sources);
        $this->assertSame(StatusLevel::Warning, $report->fileStatus('config.env'));
        $this->assertSame(StatusLevel::Warning, $report->fileStatus('mail.config.env'));
    }

    public function testJsonSelectionLoadsEnvDependenciesButHidesStandaloneEnvProblems(): void
    {
        $this->writeMinimalEnv();
        file_put_contents($this->configPath . '/config.env', "DOMAIN=\nPROVENANCE_WEBHOOK=https://example.com/hook\n");
        file_put_contents($this->configPath . '/channels.json', '{"channels":{"test":{"type":"webhook","url_env":"PROVENANCE_WEBHOOK","template":"test"}}}');

        $report = (new InstanceConfigValidator())->validate($this->configPath, 'channels.json');

        $this->assertSame(['channels.json' => true], $report->files);
        $this->assertSame(StatusLevel::Ok, $report->level());
        $this->assertSame([], $report->problems);
        $this->assertSame('https://example.com/hook', $_ENV['PROVENANCE_WEBHOOK']);
    }

    public function testServerSelectionIncludesCompanionWithoutEnvDiagnostics(): void
    {
        $this->writeMinimalEnv();
        file_put_contents($this->configPath . '/config.env', 'DOMAIN=');
        file_put_contents($this->configPath . '/servers.secrets.json', '{broken');

        $report = (new InstanceConfigValidator())->validate($this->configPath, 'servers.secrets.json');

        $this->assertSame(['servers.json' => true, 'servers.secrets.json' => true], $report->files);
        $this->assertSame(StatusLevel::Error, $report->level());
        foreach ($report->problems as $problem) {
            $this->assertSame(['servers.secrets.json'], $problem->files());
        }
    }

    private function writeMinimalEnv(): void
    {
        $_ENV = $_SERVER = [];
        file_put_contents($this->configPath . '/config.env', "DOMAIN=example.com\n");
        file_put_contents($this->configPath . '/db.config.env', "DB_HOST=localhost\nDB_USERNAME=mata\nDB_PASSWORD=secret\nDB_DATABASE=mata\nDB_PORT=3306\n");
        file_put_contents($this->configPath . '/mail.config.env', "SYM_MAILER_DSN=null://null\nSYM_FROM_EMAIL=monitor@example.com\n");
    }

    public function testCopiedShippedPolicyIsAnErrorBecauseOpaWouldNotLoad(): void
    {
        $this->writeMinimalEnv();
        mkdir($this->configPath . '/policy');
        copy(dirname(__DIR__, 4) . '/policy/alarms.rego', $this->configPath . '/policy/alarms.rego');

        $problems = $this->problemsOfType((new InstanceConfigValidator())->validate($this->configPath), 'config.policy.package.invalid');

        $this->assertCount(1, $problems);
        $this->assertSame(StatusLevel::Error, $problems[0]->level);
        $this->assertSame(['policy/alarms.rego'], $problems[0]->files());
    }

    public function testLocalPolicyThatTheDecisionPathDoesNotQueryWarns(): void
    {
        $this->writeMinimalEnv();
        mkdir($this->configPath . '/policy');
        file_put_contents($this->configPath . '/policy/alarms.rego', "package local.alarms\n\nimport data.alarms as shipped\n\ndecision := shipped.decision\n");

        $problems = $this->problemsOfType((new InstanceConfigValidator())->validate($this->configPath), 'config.policy.unused');

        $this->assertCount(1, $problems);
        $this->assertSame(StatusLevel::Warning, $problems[0]->level);
    }

    /** @return list<ConfigProblem> */
    private function problemsOfType(ConfigValidationReport $report, string $type): array
    {
        return array_values(array_filter($report->problems, static fn (ConfigProblem $problem): bool => $problem->type === $type));
    }

    public function testShippedTemplatesHaveNoErrors(): void
    {
        $report = (new InstanceConfigValidator())->validate($this->configPath);

        $errors = array_filter($report->problems, static fn (ConfigProblem $problem): bool => $problem->level === StatusLevel::Error);
        $this->assertSame([], array_map(static fn (ConfigProblem $problem): string => implode(', ', $problem->files()) . ": {$problem->message}", array_values($errors)));
    }

    public function testFilesAndServersTheAppWouldIgnoreAreErrorsOfTheirOwnFile(): void
    {
        file_put_contents($this->configPath . '/ttl.json', '{"broken":');
        file_put_contents($this->configPath . '/servers.json', json_encode([
            'web-01' => ['title' => 'Web', 'url' => 'https://web.example.com/', 'api_url' => 'https://node.example.com/', 'api_key' => 'inline'],
        ]));

        $report = (new InstanceConfigValidator())->validate($this->configPath);

        $this->assertSame(StatusLevel::Error, $report->level());
        $this->assertSame(StatusLevel::Error, $report->fileStatus('ttl.json'));
        $this->assertSame(StatusLevel::Error, $report->fileStatus('servers.json'));
        $this->assertSame(StatusLevel::Ok, $report->fileStatus('servers.secrets.json'));
    }
}
