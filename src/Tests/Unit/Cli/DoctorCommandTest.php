<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Cli;

use Mata\Cli\CliConfig;
use Mata\Cli\Command\DoctorCommand;
use Mata\Services\Status\StatusLevel;
use Mata\Utils\PathResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @internal
 *
 * @coversNothing
 */
final class DoctorCommandTest extends TestCase
{
    private string $path;

    /** @var array<string,mixed> */
    private array $env;

    protected function setUp(): void
    {
        $this->env = $_ENV;
        $this->path = sys_get_temp_dir() . '/mata-doctor-' . bin2hex(random_bytes(4));
        mkdir($this->path);
        mkdir($this->path . '/private');
        file_put_contents($this->path . '/private/users.json', '{}');
        chmod($this->path . '/private/users.json', 0o600);
        $_ENV['MATA_USERS_FILE'] = $this->path . '/private/users.json';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        chmod($this->path . '/private', 0o700);
        if (is_link($this->path . '/users.json')) {
            unlink($this->path . '/users.json');
        }
        unlink($this->path . '/private/users.json');
        rmdir($this->path . '/private');
        rmdir($this->path);
    }

    public function testInaccessibleAncestorDoesNotSuggestCreatingAUser(): void
    {
        // Keep read permission: listing a directory is not permission to traverse it.
        chmod($this->path . '/private', 0o400);
        clearstatcache();
        if (is_executable($this->path . '/private')) {
            $this->markTestSkipped('This account bypasses directory search permissions (for example root).');
        }
        $this->assertFalse(is_file($_ENV['MATA_USERS_FILE']));
        $this->assertAccessFailure($this->usersCheck(), 'permission denied');

        // Also find the blocked ancestor when the immediate parent cannot be inspected.
        $_ENV['MATA_USERS_FILE'] = $this->path . '/private/nested/users.json';
        $this->assertAccessFailure($this->usersCheck(), 'permission denied');
    }

    public function testSearchOnlyParentStillAllowsCheckingAnExistingFile(): void
    {
        chmod($this->path . '/private', 0o100);
        $check = $this->usersCheck();

        $this->assertSame(StatusLevel::Ok, $check['level']);
        $this->assertSame($_ENV['MATA_USERS_FILE'], $check['message']);
        $this->assertNull($check['hint']);
    }

    public function testConfirmedAbsenceSuggestsCreatingAUser(): void
    {
        $_ENV['MATA_USERS_FILE'] = $this->path . '/private/absent.json';
        $check = $this->usersCheck();

        $this->assertSame(StatusLevel::Error, $check['level']);
        $this->assertStringContainsString(' missing;', $check['message']);
        $this->assertSame('bin/mata user create <name> admin --generate-password', $check['hint']);
    }

    public function testUnlistableParentDoesNotConfirmAbsence(): void
    {
        chmod($this->path . '/private', 0o100);
        clearstatcache();
        if (is_readable($this->path . '/private')) {
            $this->markTestSkipped('This account bypasses directory read permissions (for example root).');
        }
        $_ENV['MATA_USERS_FILE'] = $this->path . '/private/absent.json';

        $this->assertAccessFailure($this->usersCheck(), 'missing or inaccessible');
    }

    public function testUnreachableParentDoesNotClaimPermissionDenied(): void
    {
        $_ENV['MATA_USERS_FILE'] = $this->path . '/absent/users.json';

        $this->assertAccessFailure($this->usersCheck(), 'missing or inaccessible');
    }

    public function testDanglingSymlinkDoesNotSuggestCreatingAUser(): void
    {
        symlink($this->path . '/absent/users.json', $this->path . '/users.json');
        $_ENV['MATA_USERS_FILE'] = $this->path . '/users.json';

        $this->assertAccessFailure($this->usersCheck(), 'missing or inaccessible');
    }

    public function testSymlinkToInaccessibleTargetDoesNotClaimAbsence(): void
    {
        symlink($this->path . '/private/users.json', $this->path . '/users.json');
        $_ENV['MATA_USERS_FILE'] = $this->path . '/users.json';
        chmod($this->path . '/private', 0o400);
        clearstatcache();
        if (is_executable($this->path . '/private')) {
            $this->markTestSkipped('This account bypasses directory search permissions (for example root).');
        }

        $this->assertAccessFailure($this->usersCheck(), 'missing or inaccessible');
    }

    public function testExistingFileWithoutWriteAccessRecommendsApplicationUser(): void
    {
        chmod($this->path . '/private/users.json', 0o400);
        clearstatcache();
        if (is_writable($this->path . '/private/users.json')) {
            $this->markTestSkipped('This account bypasses file write permissions (for example root).');
        }

        $this->assertAccessFailure($this->usersCheck(), 'not readable and writable');
    }

    public function testExistingInsecureFileStillFails(): void
    {
        chmod($this->path . '/private/users.json', 0o644);
        $check = $this->usersCheck();

        $this->assertSame(StatusLevel::Error, $check['level']);
        $this->assertStringContainsString('has mode 644', $check['message']);
        $this->assertSame('chmod 600 ' . $_ENV['MATA_USERS_FILE'], $check['hint']);
    }

    /** @return array{check:string,level:StatusLevel,message:string,hint:?string} */
    private function usersCheck(): array
    {
        clearstatcache();
        $command = new DoctorCommand(new CliConfig(PathResolver::fromAppPath(dirname(__DIR__, 4))));
        (new ReflectionMethod($command, 'checkUsersFile'))->invoke($command);
        $checks = (new ReflectionProperty($command, 'checks'))->getValue($command);
        $this->assertCount(1, $checks);
        $this->assertSame('users', $checks[0]['check']);

        return $checks[0];
    }

    /** @param array{check:string,level:StatusLevel,message:string,hint:?string} $check */
    private function assertAccessFailure(array $check, string $message): void
    {
        $this->assertSame(StatusLevel::Error, $check['level']);
        $this->assertStringContainsString($message, $check['message']);
        $this->assertStringNotContainsString('every route fails', $check['message']);
        $this->assertStringContainsString('Run doctor as the application user', $check['hint']);
        $this->assertStringNotContainsString('user create', $check['hint']);
    }
}
