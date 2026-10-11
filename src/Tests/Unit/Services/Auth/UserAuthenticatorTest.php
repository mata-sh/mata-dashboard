<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Auth;

use Mata\Core\Session;
use Mata\Models\UserRepository;
use Mata\Services\Auth\UserAdministrator;
use Mata\Services\Auth\UserAuthenticator;
use Mata\Tests\Helpers\UsersFileFactory;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(UserAuthenticator::class)]
class UserAuthenticatorTest extends TestCase
{
    private string $usersFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usersFile = $this->createTempFile('', '.json');
        UsersFileFactory::write($this->usersFile, [
            UsersFileFactory::record('admin', 'admin123', 'admin'),
            UsersFileFactory::record('viewer', 'viewer123'),
        ]);
        $this->startTestRequest();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->cleanupTempFiles([$this->usersFile, $this->usersFile . '.lock']);
        parent::tearDown();
    }

    public function testItSignsInWithCorrectCredentials(): void
    {
        $auth = $this->request();
        $this->assertFalse($auth->check());

        $this->assertTrue($auth->login('admin', 'admin123'));

        $this->assertTrue($auth->check());
        $this->assertSame('admin', $auth->getUsername());
        $this->assertTrue($auth->hasRole('admin'));
        $this->assertFalse($auth->hasRole('viewer'));
        $this->assertSame(
            ['username' => 'admin', 'role' => 'admin', 'id' => 'user_admin', 'auth_revision' => $this->stored('admin')['auth_revision']],
            $auth->getUser(),
        );
    }

    public function testItRejectsWrongPasswordsAndUnknownUsers(): void
    {
        $auth = $this->request();

        $this->assertFalse($auth->login('admin', 'wrong_password'));
        $this->assertFalse($auth->login('nobody', 'any_password'));

        $this->assertFalse($auth->check());
        $this->assertNull($auth->getUser());
        $this->assertNull($auth->getUsername());
    }

    public function testItRegeneratesTheSessionIdOnSignIn(): void
    {
        $before = session_id();

        $this->request()->login('admin', 'admin123');

        $this->assertNotSame($before, session_id());
    }

    public function testItSignsOut(): void
    {
        $auth = $this->request();
        $auth->login('admin', 'admin123', true);

        $auth->logout();

        $this->assertFalse($auth->check());
        $this->assertNull($auth->getUser());
        $this->assertArrayNotHasKey('remember_token_hash', $this->stored('admin'));
        $this->assertArrayNotHasKey('remember_expires', $this->stored('admin'));
    }

    public function testItLocksTheAccountAfterFiveFailedAttempts(): void
    {
        $auth = $this->request();
        for ($i = 0; $i < 5; ++$i) {
            $this->assertFalse($auth->login('viewer', 'wrong_password'));
        }

        $this->assertFalse($auth->login('viewer', 'viewer123'), 'The correct password must fail while locked');
        $this->assertFalse($auth->check());
        $this->assertGreaterThan(time(), $this->stored('viewer')['locked_until']);
    }

    public function testItResetsFailedAttemptsAfterSuccessfulSignIn(): void
    {
        $auth = $this->request();
        for ($i = 0; $i < 3; ++$i) {
            $auth->login('viewer', 'wrong_password');
        }

        $this->assertTrue($auth->login('viewer', 'viewer123'));
        $this->assertSame(0, $this->stored('viewer')['failed_attempts']);
    }

    public function testItGrantsAFreshAttemptWindowAfterLockoutExpires(): void
    {
        UsersFileFactory::change($this->usersFile, 'viewer', static fn (array $user): array => [
            ...$user,
            'failed_attempts' => 5,
            'locked_until' => time() - 60,
        ]);
        $auth = $this->request();

        // One typo after the lockout expired must not re-lock the account for another full window.
        $this->assertFalse($auth->login('viewer', 'typo_password'));
        $this->assertTrue($auth->login('viewer', 'viewer123'));
    }

    /**
     * Parallel requests all load users.json before any of them records its failure.
     */
    public function testConcurrentFailedSignInsAllCountTowardsLockout(): void
    {
        $requests = [];
        for ($i = 0; $i < 6; ++$i) {
            $requests[] = $this->request();
        }
        $lateRequest = array_pop($requests);

        foreach ($requests as $request) {
            $this->assertFalse($request->login('admin', 'wrong_password'));
        }

        $this->assertSame(5, $this->stored('admin')['failed_attempts']);
        $this->assertFalse($lateRequest->login('admin', 'admin123'), 'A request loaded before the lockout must not sign in');
        $this->assertFalse($lateRequest->check());
    }

    public function testAPasswordVerifiedBeforeAResetCannotSignIn(): void
    {
        $staleRequest = $this->request();
        $this->administrator()->changePassword('admin', 'replacement');
        $afterReset = file_get_contents($this->usersFile);
        $sessionId = session_id();

        $this->assertFalse($staleRequest->login('admin', 'admin123', true));

        $this->assertSame($afterReset, file_get_contents($this->usersFile));
        $this->assertFalse($staleRequest->check());
        $this->assertSame($sessionId, session_id());
    }

    public function testItUsesAWellFormedDummyHashAtTheDefaultCost(): void
    {
        $hash = (new ReflectionMethod(UserAuthenticator::class, 'dummyPasswordHash'))->invoke(null);

        $this->assertSame('bcrypt', password_get_info($hash)['algoName']);
        $this->assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT), 'Dummy hash must cost as much as a current password hash');
    }

    /**
     * Response time must not reveal which usernames exist or are locked.
     *
     * The broken path was ~2000x faster than bcrypt, so a 4x margin on the fastest of three runs stays stable on noisy runners.
     */
    public function testItRejectsUnknownAndLockedUsersAsSlowlyAsWrongPasswords(): void
    {
        UsersFileFactory::change($this->usersFile, 'viewer', static fn (array $user): array => [
            ...$user,
            'failed_attempts' => 5,
            'locked_until' => time() + 900,
        ]);
        $auth = $this->request();

        $fastestRejection = function (string $username, string $password) use ($auth): float {
            $fastest = INF;
            for ($i = 0; $i < 3; ++$i) {
                $start = hrtime(true);
                $this->assertFalse($auth->login($username, $password));
                $fastest = min($fastest, hrtime(true) - $start);
            }

            return $fastest;
        };

        $wrongPassword = $fastestRejection('admin', 'wrong_password');

        $this->assertGreaterThan($wrongPassword / 4, $fastestRejection('nobody', 'any_password'), 'Unknown user rejected too quickly');
        $this->assertGreaterThan($wrongPassword / 4, $fastestRejection('viewer', 'viewer123'), 'Locked account rejected too quickly');
    }

    public function testItRehashesOutdatedPasswordHashes(): void
    {
        $oldHash = password_hash('viewer123', PASSWORD_BCRYPT, ['cost' => 4]);
        UsersFileFactory::change($this->usersFile, 'viewer', static fn (array $user): array => [...$user, 'password_hash' => $oldHash]);

        $this->assertTrue($this->request()->login('viewer', 'viewer123'));

        $newHash = $this->stored('viewer')['password_hash'];
        $this->assertNotSame($oldHash, $newHash);
        $this->assertFalse(password_needs_rehash($newHash, PASSWORD_DEFAULT));
        $this->assertTrue(password_verify('viewer123', $newHash));
    }

    public function testItValidatesTheSessionAgainstTheStoredAccount(): void
    {
        $auth = $this->request();
        $auth->login('admin', 'admin123');

        $_SESSION['user']['role'] = 'viewer';
        $this->assertTrue($auth->hasRole('admin'), 'The role comes from users.json, not the session');
        $this->assertSame('admin', $auth->getUser()['role']);

        $this->administrator()->changeRole('admin', 'viewer');
        $auth = $this->request($_SESSION);
        $this->assertNull($auth->getUser(), 'A role change signs the account out');
        $this->assertFalse($auth->hasRole('admin'));
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'check() never destroys the session');

        $this->assertTrue($auth->login('admin', 'admin123'));
        $this->assertTrue($auth->hasRole('viewer'));
        unset($_SESSION['user']['auth_revision']);
        $this->assertNull($auth->getUsername());
    }

    public function testItStoresOnlyTheRememberTokenHash(): void
    {
        $this->assertTrue($this->request()->login('admin', 'admin123', true));

        $stored = $this->stored('admin');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored['remember_token_hash']);
        $this->assertGreaterThan(time(), $stored['remember_expires']);
        $this->assertSame($stored['auth_revision'], Session::get('user')['auth_revision']);
    }

    public function testItSignsInFromARememberCookieAndRotatesTheToken(): void
    {
        $cookie = $this->seedRememberToken('admin', time() + 3600);
        $before = $this->stored('admin')['remember_token_hash'];

        $auth = $this->request(cookie: $cookie);

        $this->assertTrue($auth->check());
        $this->assertSame('admin', $auth->getUsername());
        $this->assertNotSame($before, $this->stored('admin')['remember_token_hash']);
        $this->assertFalse($this->request(cookie: $cookie)->check(), 'A used token must not sign in again');
    }

    public function testItIgnoresExpiredOrForgedRememberCookies(): void
    {
        $expired = $this->seedRememberToken('admin', time() - 1);
        $this->assertFalse($this->request(cookie: $expired)->check());

        $this->seedRememberToken('admin', time() + 3600);
        $this->assertFalse($this->request(cookie: 'admin|' . str_repeat('0', 64))->check());
        $this->assertFalse($this->request(cookie: 'admin')->check());
        $this->assertFalse($this->request(cookie: 'nobody|token')->check());
    }

    public function testItLeavesAnAuthenticatedSessionAloneWhenARememberCookieIsPresent(): void
    {
        $this->request()->login('viewer', 'viewer123');
        $cookie = $this->seedRememberToken('admin', time() + 3600);
        $token = $this->stored('admin')['remember_token_hash'];

        $auth = $this->request($_SESSION, $cookie);

        $this->assertSame('viewer', $auth->getUsername());
        $this->assertSame($token, $this->stored('admin')['remember_token_hash']);
    }

    /** @param array<string,mixed> $session */
    private function request(array $session = [], ?string $cookie = null): UserAuthenticator
    {
        Session::close();
        Session::start();
        $_SESSION = $session;

        $auth = new UserAuthenticator(UserRepository::open($this->usersFile), new NullLogger(), false);
        $auth->resumeFromRememberCookie($cookie);

        return $auth;
    }

    private function administrator(): UserAdministrator
    {
        return new UserAdministrator(UserRepository::openForAdministration($this->usersFile), new NullLogger());
    }

    /** CLI cannot inspect Set-Cookie headers; seed a known browser credential instead. */
    private function seedRememberToken(string $username, int $expires): string
    {
        $token = bin2hex(random_bytes(32));
        UsersFileFactory::change($this->usersFile, $username, static fn (array $user): array => [
            ...$user,
            'remember_token_hash' => hash('sha256', $token),
            'remember_expires' => $expires,
        ]);

        return $username . '|' . $token;
    }

    /** @return array<string,mixed> */
    private function stored(string $username): array
    {
        return UsersFileFactory::find($this->usersFile, $username) ?? $this->fail("User {$username} is not stored");
    }
}
