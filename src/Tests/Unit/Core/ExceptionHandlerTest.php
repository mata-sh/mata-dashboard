<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Core;

use ErrorException;
use Mata\Core\ExceptionHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \Mata\Core\ExceptionHandler
 *
 * @internal
 */
final class ExceptionHandlerTest extends TestCase
{
    private int $errorReporting;
    private string $displayErrors;

    protected function setUp(): void
    {
        $this->errorReporting = error_reporting();
        $this->displayErrors = (string) ini_get('display_errors');
    }

    protected function tearDown(): void
    {
        error_reporting($this->errorReporting);
        ini_set('display_errors', $this->displayErrors);
        unset($_ENV['DEBUG'], $_SERVER['HTTP_ACCEPT']);
        parent::tearDown();
    }

    public function testProductionErrorsRemainHandledWithoutConversion(): void
    {
        $handler = new ExceptionHandler(new NullLogger(), false);

        self::assertTrue($handler->handleError(E_WARNING, 'warning', __FILE__, __LINE__));
    }

    public function testDebugErrorsBecomeErrorExceptions(): void
    {
        $handler = new ExceptionHandler(new NullLogger(), true);
        $this->expectException(ErrorException::class);

        $handler->handleError(E_WARNING, 'warning', __FILE__, __LINE__);
    }

    public function testProductionResponseNeverExposesExceptionDetails(): void
    {
        $_ENV['DEBUG'] = '1';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $method = new ReflectionMethod(ExceptionHandler::class, 'sendErrorResponse');

        ob_start();
        $method->invoke(new ExceptionHandler(new NullLogger(), false), new RuntimeException('database password secret'));
        $output = ob_get_clean();

        self::assertIsString($output);
        self::assertStringNotContainsString('database password secret', $output);
        self::assertStringContainsString('A server error occurred', $output);
    }

    public function testRegisterInstallsApplicationHandlers(): void
    {
        $handler = new ExceptionHandler(new Logger('test'), false);
        $handler->register();

        $previousError = set_error_handler(static fn (): bool => true);
        $previousException = set_exception_handler(static function (): void {});

        self::assertSame([$handler, 'handleError'], $previousError);
        self::assertSame([$handler, 'handle'], $previousException);

        restore_error_handler();
        restore_error_handler();
        restore_exception_handler();
        restore_exception_handler();
    }
}
