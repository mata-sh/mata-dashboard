<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Validation\ConfigProblem;
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
        array_map('unlink', glob($this->configPath . '/*') ?: []);
        rmdir($this->configPath);
    }

    public function testShippedTemplatesHaveNoErrors(): void
    {
        $report = (new InstanceConfigValidator())->validate($this->configPath);

        $errors = array_filter($report->problems, static fn (ConfigProblem $problem): bool => $problem->level === ConfigProblem::ERROR);
        $this->assertSame([], array_map(static fn (ConfigProblem $problem): string => "{$problem->file}: {$problem->message}", array_values($errors)));
    }

    public function testFilesAndServersTheAppWouldIgnoreAreErrorsOfTheirOwnFile(): void
    {
        file_put_contents($this->configPath . '/ttl.json', '{"broken":');
        file_put_contents($this->configPath . '/servers.json', json_encode([
            'web-01' => ['title' => 'Web', 'url' => 'https://web.example.com/', 'api_url' => 'https://node.example.com/', 'api_key' => 'inline'],
        ]));

        $report = (new InstanceConfigValidator())->validate($this->configPath);

        $this->assertSame(StatusLevel::Error, $report->level());
        $this->assertSame(ConfigProblem::ERROR, $report->fileStatus('ttl.json'));
        $this->assertSame(ConfigProblem::ERROR, $report->fileStatus('servers.json'));
        $this->assertSame('ok', $report->fileStatus('servers.secrets.json'));
    }
}
