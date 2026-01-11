<?php

declare(strict_types=1);

namespace Mata\Utils;

use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Small helper for persisting associative state arrays inside the temp directory.
 *
 * Provides:
 * - Directory creation with error reporting
 * - JSON encoding/decoding with safe fallbacks
 * - Optional post-processing of decoded data
 */
final class FileStateStore
{
    private string $directory;
    private string $filePath;
    private LoggerInterface $logger;

    public function __construct(string $directory, string $fileName, LoggerInterface $logger)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $this->filePath = $this->directory . DIRECTORY_SEPARATOR . $fileName;
        $this->logger = $logger;
    }

    /**
     * Load state from disk with default fallback and optional mapper.
     *
     * @param callable():array      $defaultFactory Returns default state when file missing or invalid
     * @param callable(array):array $mapper         Allows caller to normalise decoded structure
     *
     * @return array Loaded state
     */
    public function load(callable $defaultFactory, ?callable $mapper = null): array
    {
        if (!is_file($this->filePath)) {
            return $defaultFactory();
        }

        try {
            $contents = file_get_contents($this->filePath);
            if ($contents === false) {
                throw new JsonException('Failed to read state file: ' . $this->filePath);
            }

            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return $defaultFactory();
            }

            return $mapper !== null ? $mapper($decoded) : $decoded;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to load state file; falling back to defaults.', [
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);

            return $defaultFactory();
        }
    }

    /**
     * Persist state to disk (best effort).
     */
    public function save(array $state): void
    {
        if (!$this->ensureDirectory()) {
            return;
        }

        try {
            $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            file_put_contents($this->filePath, (string) $encoded, LOCK_EX);
        } catch (Throwable $e) {
            $this->logger->error('Failed to persist state file.', [
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return bool True if directory exists or was created successfully
     */
    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }

        if (@mkdir($this->directory, 0777, true) || is_dir($this->directory)) {
            return true;
        }

        $this->logger->error('Failed to create state directory.', [
            'directory' => $this->directory,
        ]);

        return false;
    }
}
