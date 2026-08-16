<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http\Security\RateLimiting;

use Mata\Http\Security\RateLimiting\RequestRateLimiter;
use Mata\Http\Security\RateLimiting\RequestRateLimiterFactory;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use Mata\Tests\TestCase;
use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\RateLimitIdentifier;
use MataSh\RateLimiter\RateLimitResult;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;

/**
 * @covers \Mata\Http\Security\RateLimiting\RequestRateLimiter
 * @covers \Mata\Http\Security\RateLimiting\RequestRateLimiterFactory
 *
 * @internal
 */
final class RequestRateLimiterTest extends TestCase
{
    public function testDisabledRateLimitingIsIntentionallyPermissive(): void
    {
        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->expects(self::never())->method('consume');
        $limiter = new RequestRateLimiter(
            $rateLimiter,
            ApplicationConfigFactory::createRateLimitingConfig(enabled: false),
            new Logger('test'),
        );

        self::assertTrue($limiter->consume('login', '127.0.0.1')->isAllowed());
    }

    public function testRuntimePackageFailureIsLoggedAndDenied(): void
    {
        $handler = new TestHandler();
        $rateLimiter = $this->createStub(RateLimiter::class);
        $rateLimiter->method('consume')->willThrowException(new RuntimeException('backend failed'));
        $limiter = new RequestRateLimiter(
            $rateLimiter,
            ApplicationConfigFactory::createRateLimitingConfig(enabled: true),
            new Logger('test', [$handler]),
        );

        $result = $limiter->consume('api', '127.0.0.1');

        self::assertFalse($result->isAllowed());
        self::assertNotNull($result->getRetryAt());
        self::assertTrue($handler->hasErrorRecords());
    }

    public function testFactoryCreatesTheConfiguredFilesystemStorage(): void
    {
        $directory = sys_get_temp_dir() . '/mata-dashboard-rate-limit-' . bin2hex(random_bytes(8));

        try {
            $config = ApplicationConfigFactory::createRateLimitingConfig(
                enabled: true,
                filePath: $directory,
                loginMaxRequests: 1,
                loginWindowSeconds: 60,
            );
            $limiter = RequestRateLimiterFactory::create($config, new Logger('test'));

            self::assertTrue($limiter->consume('login', '127.0.0.1')->isAllowed());
            self::assertFalse($limiter->consume('login', '127.0.0.1')->isAllowed());
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_file($directory . '/.cleanup.lock')) {
                unlink($directory . '/.cleanup.lock');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testEnabledInitializationFailureDeniesProtectedRequests(): void
    {
        $handler = new TestHandler();
        $config = ApplicationConfigFactory::createRateLimitingConfig(
            enabled: true,
            storageType: 'redis',
            redisHost: '127.0.0.1',
            redisPort: 1,
        );
        $limiter = RequestRateLimiterFactory::create($config, new Logger('test', [$handler]));

        self::assertFalse($limiter->consume('login', '127.0.0.1')->isAllowed());
        self::assertTrue($handler->hasCriticalRecords());
    }

    public function testRouteGroupsUseIndependentIdentifiers(): void
    {
        $identifiers = [];
        $rateLimiter = $this->createStub(RateLimiter::class);
        $rateLimiter->method('consume')->willReturnCallback(
            static function (string $identifier) use (&$identifiers): RateLimitResult {
                $identifiers[] = $identifier;

                return new RateLimitResult(true, 1, null);
            },
        );
        $limiter = new RequestRateLimiter(
            $rateLimiter,
            ApplicationConfigFactory::createRateLimitingConfig(enabled: true),
            new Logger('test'),
        );

        self::assertTrue($limiter->consume('login', '127.0.0.1')->isAllowed());
        self::assertTrue($limiter->consume('api', '127.0.0.1')->isAllowed());
        self::assertSame([
            'login_' . RateLimitIdentifier::fromIp('127.0.0.1'),
            'api_' . RateLimitIdentifier::fromIp('127.0.0.1'),
        ], $identifiers);
    }

    public function testConfiguredMaximumAboveOneHundredIsPassedToPackage(): void
    {
        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->expects(self::once())
            ->method('consume')
            ->with('api_' . RateLimitIdentifier::fromIp('127.0.0.1'), 125, 60)
            ->willReturn(new RateLimitResult(true, 1, null));
        $limiter = new RequestRateLimiter(
            $rateLimiter,
            ApplicationConfigFactory::createRateLimitingConfig(
                enabled: true,
                apiMaxRequests: 125,
            ),
            new Logger('test'),
        );

        self::assertTrue($limiter->consume('api', '127.0.0.1')->isAllowed());
    }

    public function testPackageRetryGuidanceIsPreserved(): void
    {
        $retryAt = time() + 30;
        $rateLimiter = $this->createStub(RateLimiter::class);
        $rateLimiter->method('consume')->willReturn(new RateLimitResult(false, 10, $retryAt));
        $limiter = new RequestRateLimiter(
            $rateLimiter,
            ApplicationConfigFactory::createRateLimitingConfig(enabled: true),
            new Logger('test'),
        );

        $result = $limiter->consume('login', '127.0.0.1');

        self::assertFalse($result->isAllowed());
        self::assertSame($retryAt, $result->getRetryAt());
    }
}
