<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Utils;

use InvalidArgumentException;
use Mata\Tests\TestCase;
use Mata\Utils\PathResolver;
use Mata\Utils\UsersFilePathResolver;

/**
 * @covers \Mata\Utils\UsersFilePathResolver
 *
 * @internal
 */
class UsersFilePathResolverTest extends TestCase
{
    private string $tempDir;
    private mixed $previousEnv;
    private mixed $previousServer;
    private false|string $previousProcessEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = $this->createTempDirectory();
        mkdir($this->tempDir . '/app/data', 0777, true);
        mkdir($this->tempDir . '/data', 0777, true);

        $this->previousEnv = $_ENV['MATA_USERS_FILE'] ?? null;
        $this->previousServer = $_SERVER['MATA_USERS_FILE'] ?? null;
        $this->previousProcessEnv = getenv('MATA_USERS_FILE');
        $this->setUsersFile(null);
    }

    protected function tearDown(): void
    {
        $this->restoreUsersFile();
        $this->cleanupTempFiles([$this->tempDir]);

        parent::tearDown();
    }

    public function testItUsesInProjectPathWhenNoPersistentPathIsConfigured(): void
    {
        $this->assertSame(
            $this->tempDir . '/app/config/users.json',
            UsersFilePathResolver::resolve($this->paths())
        );
    }

    public function testItUsesConfiguredPersistentPathOutsideTheApplication(): void
    {
        $usersFile = $this->tempDir . '/data/users.json';
        $this->setUsersFile($usersFile);

        $this->assertSame($usersFile, UsersFilePathResolver::resolve($this->paths()));
    }

    public function testItRejectsRelativeConfiguredPath(): void
    {
        $this->setUsersFile('data/users.json');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute path');

        UsersFilePathResolver::resolve($this->paths());
    }

    public function testItRejectsConfiguredPathInsideApplicationDirectory(): void
    {
        $this->setUsersFile($this->tempDir . '/app/data/users.json');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be outside the application directory');

        UsersFilePathResolver::resolve($this->paths());
    }

    public function testItRejectsPathInNonexistentDirectoryInsideApplicationDirectory(): void
    {
        $this->setUsersFile($this->tempDir . '/app/not-created/users.json');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be outside the application directory');

        UsersFilePathResolver::resolve($this->paths());
    }

    private function paths(): PathResolver
    {
        return PathResolver::fromAppPath($this->tempDir . '/app');
    }

    private function setUsersFile(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['MATA_USERS_FILE'], $_SERVER['MATA_USERS_FILE']);
            putenv('MATA_USERS_FILE');

            return;
        }

        $_ENV['MATA_USERS_FILE'] = $value;
        $_SERVER['MATA_USERS_FILE'] = $value;
        putenv("MATA_USERS_FILE={$value}");
    }

    private function restoreUsersFile(): void
    {
        $this->setUsersFile(null);

        if ($this->previousEnv !== null) {
            $_ENV['MATA_USERS_FILE'] = $this->previousEnv;
        }
        if ($this->previousServer !== null) {
            $_SERVER['MATA_USERS_FILE'] = $this->previousServer;
        }
        if ($this->previousProcessEnv !== false) {
            putenv("MATA_USERS_FILE={$this->previousProcessEnv}");
        }
    }
}
