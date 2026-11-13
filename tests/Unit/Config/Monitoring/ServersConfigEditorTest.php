<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config\Monitoring;

use Mata\Config\Monitoring\ServersConfigEditor;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ServersConfigEditorTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . '/mata-servers-editor-' . bin2hex(random_bytes(4));
        mkdir($this->configPath);
        file_put_contents($this->configPath . '/servers.json', json_encode(['_README' => ['description' => 'kept'], 'old' => ['title' => 'Old', 'api_url' => 'https://old.example.com/']]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->configPath . '/*') ?: []);
        rmdir($this->configPath);
    }

    /** The web server reads the secrets through their group; losing 0640 fails the whole servers load. */
    public function testSecretsKeepTheirModeAndNewOnesAreGroupReadable(): void
    {
        $editor = new ServersConfigEditor($this->configPath);

        $editor->add('web-01', ['title' => 'Web', 'api_url' => 'https://node.example.com/'], 'key-1');
        $this->assertSame('640', decoct(fileperms($this->configPath . '/servers.secrets.json') & 0777));

        chmod($this->configPath . '/servers.secrets.json', 0660);
        $editor->add('web-02', ['title' => 'Web 2', 'api_url' => 'https://node2.example.com/'], 'key-2');

        $this->assertSame('660', decoct(fileperms($this->configPath . '/servers.secrets.json') & 0777));
        $this->assertSame(['_README', 'old', 'web-01', 'web-02'], array_keys($this->decode('servers.json')));
        $this->assertSame(['web-01' => ['api_key' => 'key-1'], 'web-02' => ['api_key' => 'key-2']], $this->decode('servers.secrets.json'));
    }

    /** @return array<string,mixed> */
    private function decode(string $file): array
    {
        return json_decode((string) file_get_contents($this->configPath . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
    }
}
