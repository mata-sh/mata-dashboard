<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Utils;

use Mata\Config\EnvConfigLoader;
use Mata\Tests\TestCase;

/**
 * Test suite for EnvConfigLoader utility class.
 *
 * @covers \Mata\Config\EnvConfigLoader
 *
 * @internal
 */
class EnvConfigLoaderTest extends TestCase
{
    private EnvConfigLoader $configLoader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configLoader = new EnvConfigLoader();
    }

    /**
     * @test
     *
     * @group configloader
     * @group initialization
     */
    public function testItInitializesSuccessfully(): void
    {
        $this->assertInstanceOf(EnvConfigLoader::class, $this->configLoader);
    }

    /**
     * @test
     *
     * @group configloader
     * @group cli-security
     */
    public function testItLoadsCliSecurityConfigWithDefaults(): void
    {
        // Given a config path without CLI security settings
        $configPath = __DIR__ . '/../../Fixtures/config-minimal';

        // When loading the config
        $config = $this->configLoader->load(['config.env'], $configPath);

        // Then CLI security config should have default values
        $this->assertFalse($config->cliSecurity->strictUserCheck, 'strictUserCheck should default to false');
        $this->assertIsArray($config->cliSecurity->allowedUsers);
        $this->assertContains('root', $config->cliSecurity->allowedUsers);
        $this->assertContains('http', $config->cliSecurity->allowedUsers);
    }

    /**
     * @test
     *
     * @group configloader
     * @group cli-security
     */
    public function testItLoadsCliSecurityConfigWithCustomValues(): void
    {
        // Given a config path with CLI security settings
        $configPath = __DIR__ . '/../../Fixtures/config-with-cli-security';

        // When loading the config
        $config = $this->configLoader->load(['config.env'], $configPath);

        // Then CLI security config should have custom values
        $this->assertTrue($config->cliSecurity->strictUserCheck, 'strictUserCheck should be true when set to ON');
        $this->assertIsArray($config->cliSecurity->allowedUsers);
        $this->assertCount(3, $config->cliSecurity->allowedUsers);
        $this->assertContains('root', $config->cliSecurity->allowedUsers);
        $this->assertContains('http', $config->cliSecurity->allowedUsers);
        $this->assertContains('testuser', $config->cliSecurity->allowedUsers);
    }
}
