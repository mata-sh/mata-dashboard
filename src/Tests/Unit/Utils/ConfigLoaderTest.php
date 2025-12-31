<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Utils;

use Watcher\Config\EnvConfigLoader;
use Watcher\Tests\TestCase;

/**
 * Test suite for EnvConfigLoader utility class.
 *
 * NOTE: EnvConfigLoader has been refactored to return DTOs via load() method.
 * The old API (loadJson, loadEnv, validate, etc.) no longer exists.
 * These tests have been removed as they tested the old, non-existent API.
 *
 * @covers \Watcher\Config\EnvConfigLoader
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
}
