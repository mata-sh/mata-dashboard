<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\EnvConfigCreator;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Config\EnvConfigCreator
 *
 * @internal
 */
final class EnvConfigCreatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/mata-env-config-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/config/example', 0700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->tempDir . '/config/db.config.env');
        @unlink($this->tempDir . '/config/example/db.config.env.example');
        @rmdir($this->tempDir . '/config/example');
        @rmdir($this->tempDir . '/config');
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    public function testCreatesDatabaseConfigFromEnvExampleTemplate(): void
    {
        $template = $this->tempDir . '/config/example/db.config.env.example';
        file_put_contents($template, "DB_HOST=localhost\n");

        $creator = new EnvConfigCreator();
        $result = $creator->createFromTemplates(
            ['db.config.env'],
            $this->tempDir . '/config',
            $this->tempDir . '/config/example'
        );

        $this->assertSame(['db.config.env' => true], $result);
        $this->assertSame("DB_HOST=localhost\n", file_get_contents($this->tempDir . '/config/db.config.env'));
    }
}
