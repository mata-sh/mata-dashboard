<?php

declare(strict_types=1);

namespace Mata\Tests;

use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;
use ReflectionException;

/**
 * Base test case with common testing utilities.
 *
 * Provides helper methods for testing private methods,
 * creating mock objects, and setting up test environments.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Call protected/private method of a class.
     *
     * @param object $obj  Instantiated object that we will run method on
     * @param string $name Method name to call
     * @param array  $args Array of arguments to pass into method
     *
     * @return mixed Method return value
     *
     * @throws ReflectionException
     */
    protected function invokeMethod(object $obj, string $name, array $args = [])
    {
        $class = new ReflectionClass($obj);
        $method = $class->getMethod($name);
        if (PHP_VERSION_ID < 80500) {
            $method->setAccessible(true);
        }

        return $method->invokeArgs($obj, $args);
    }

    /**
     * Get protected/private property value.
     *
     * @param object $obj  Instantiated object
     * @param string $name Property name
     *
     * @return mixed Property value
     *
     * @throws ReflectionException
     */
    protected function getProperty(object $obj, string $name)
    {
        $class = new ReflectionClass($obj);
        $property = $class->getProperty($name);
        if (PHP_VERSION_ID < 80500) {
            $property->setAccessible(true);
        }

        return $property->getValue($obj);
    }

    /**
     * Set protected/private property value.
     *
     * @param object $obj   Instantiated object
     * @param string $name  Property name
     * @param mixed  $value Value to set
     *
     * @throws ReflectionException
     */
    protected function setProperty(object $obj, string $name, $value): void
    {
        $class = new ReflectionClass($obj);
        $property = $class->getProperty($name);
        if (PHP_VERSION_ID < 80500) {
            $property->setAccessible(true);
        }
        $property->setValue($obj, $value);
    }

    protected function startTestRequest(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    /**
     * Create temporary file for testing.
     *
     * @param string $content   File content
     * @param string $extension File extension (default: .tmp)
     *
     * @return string Temporary file path
     */
    protected function createTempFile(string $content = '', string $extension = '.tmp'): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'watcher_test') . $extension;
        file_put_contents($tempFile, $content);

        return $tempFile;
    }

    /**
     * Create temporary directory for testing.
     *
     * @return string Temporary directory path
     */
    protected function createTempDirectory(): string
    {
        $tempDir = sys_get_temp_dir() . '/watcher_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        return $tempDir;
    }

    /**
     * Clean up temporary files and directories.
     *
     * @param array $paths Paths to clean up
     */
    protected function cleanupTempFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }
    }

    /**
     * Remove directory and all its contents recursively.
     *
     * @param string $dir Directory path
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Assert that an array has the expected structure.
     *
     * @param array $expectedStructure Expected keys and types
     * @param array $actual            Actual array to test
     */
    protected function assertArrayStructure(array $expectedStructure, array $actual): void
    {
        foreach ($expectedStructure as $key => $expectedType) {
            $this->assertArrayHasKey($key, $actual, "Array missing required key: {$key}");

            if ($expectedType !== null) {
                $actualType = gettype($actual[$key]);
                $this->assertEquals($expectedType, $actualType, "Key '{$key}' has wrong type. Expected {$expectedType}, got {$actualType}");
            }
        }
    }

    /** A real EventManager around a recorder double, so recordEvent() expectations still apply. */
    protected function eventManagerFor(EventRecorderService $recorder): EventManager
    {
        return new EventManager(ApplicationConfigFactory::create(), eventRecorder: $recorder);
    }
}
