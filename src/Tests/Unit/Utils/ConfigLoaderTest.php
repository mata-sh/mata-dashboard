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

    public function testItLoadsSecurityHeadersSetting(): void
    {
        $previousSetting = $_ENV['SECURITY_HEADERS_ENABLED'] ?? null;
        $_ENV['SECURITY_HEADERS_ENABLED'] = 'OFF';

        try {
            $configPath = __DIR__ . '/../../Fixtures/config-minimal';
            $config = $this->configLoader->load(['config.env'], $configPath);

            $this->assertFalse($config->general->securityHeadersEnabled);
        } finally {
            if ($previousSetting === null) {
                unset($_ENV['SECURITY_HEADERS_ENABLED']);
            } else {
                $_ENV['SECURITY_HEADERS_ENABLED'] = $previousSetting;
            }
        }
    }

    public function testItLoadsAnUnsetDashboardUrlAsNull(): void
    {
        $previous = $_ENV['MAIL_DASHBOARD_URL'] ?? null;
        unset($_ENV['MAIL_DASHBOARD_URL']);

        try {
            $config = $this->configLoader->load(['config.env'], __DIR__ . '/../../Fixtures/config-minimal');
            $this->assertNull($config->mail->dashboardUrl);
        } finally {
            if ($previous !== null) {
                $_ENV['MAIL_DASHBOARD_URL'] = $previous;
            }
        }
    }

    public function testItLoadsAnEmptyDashboardUrlAsNull(): void
    {
        $previous = $_ENV['MAIL_DASHBOARD_URL'] ?? null;
        $_ENV['MAIL_DASHBOARD_URL'] = '';

        try {
            $config = $this->configLoader->load(['config.env'], __DIR__ . '/../../Fixtures/config-minimal');
            $this->assertNull($config->mail->dashboardUrl);
        } finally {
            if ($previous === null) {
                unset($_ENV['MAIL_DASHBOARD_URL']);
            } else {
                $_ENV['MAIL_DASHBOARD_URL'] = $previous;
            }
        }
    }

    public function testItPassesConfiguredDashboardUrlThroughWithoutValidation(): void
    {
        $previous = $_ENV['MAIL_DASHBOARD_URL'] ?? null;
        $_ENV['MAIL_DASHBOARD_URL'] = 'not-a-url';

        try {
            $config = $this->configLoader->load(['config.env'], __DIR__ . '/../../Fixtures/config-minimal');
            $this->assertSame('not-a-url', $config->mail->dashboardUrl);
        } finally {
            if ($previous === null) {
                unset($_ENV['MAIL_DASHBOARD_URL']);
            } else {
                $_ENV['MAIL_DASHBOARD_URL'] = $previous;
            }
        }
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
