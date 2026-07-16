<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use FilesystemIterator;
use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
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
                    'data_retention.json',
                    'intervals.json',
                    'users.json',
                    'events.json',
                ],
                array_column($files, 'name')
            );
            $this->assertTrue($filesByName['limits.json']['exists']);
            $this->assertTrue($filesByName['thresholds.json']['exists']);
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

    private function createRenderer(string $appPath): SettingsRenderer
    {
        $paths = $this->createStub(PathResolver::class);
        $paths->appPath = $appPath;
        $paths->method('toRelativePath')->willReturnCallback(
            static fn (string $path): string => str_replace($appPath . '/', '', $path)
        );

        $app = $this->createStub(App::class);
        $app->paths = $paths;

        return new SettingsRenderer(
            $this->createStub(Environment::class),
            $this->createStub(Logger::class),
            $this->createStub(Tools::class),
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
