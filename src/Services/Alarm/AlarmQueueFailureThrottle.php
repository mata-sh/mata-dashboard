<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Filesystem-backed throttle for direct alarms that cannot be persisted to the delivery queue.
 *
 * Stores the last trigger timestamp per `(server, channel)` as lock files beneath
 * a configurable directory. Intended for best-effort suppression during queue-storage outages.
 */
final class AlarmQueueFailureThrottle
{
    private string $baseDirectory;
    private LoggerInterface $logger;

    public function __construct(string $baseDirectory, ?LoggerInterface $logger = null)
    {
        $this->baseDirectory = rtrim($baseDirectory, DIRECTORY_SEPARATOR);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Attempt to acquire a lock for the given server/channel pair.
     *
     * Returns true when enough time has elapsed since the previous acquisition,
     * and updates the lock timestamp. Returns false when still inside the cooldown window.
     */
    public function tryAcquire(string $serverKey, string $channel, int $spacingSeconds): bool
    {
        $spacingSeconds = max(1, $spacingSeconds);
        $path = $this->lockFilePath($serverKey, $channel);

        if (!$this->ensureDirectory(dirname($path))) {
            return false;
        }

        $now = time();

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            $this->logger->warning("Failed to open lock file [file={$path}]");

            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                $this->logger->warning("Failed to acquire lock for lock file [file={$path}]");

                return false;
            }

            $content = stream_get_contents($handle);
            $lastTimestamp = $content !== false ? (int) trim($content) : 0;

            if ($lastTimestamp > 0 && ($now - $lastTimestamp) < $spacingSeconds) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $now);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Remove an existing lock, allowing the next acquisition immediately.
     */
    public function release(string $serverKey, string $channel): void
    {
        $path = $this->lockFilePath($serverKey, $channel);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Inspect last acquisition timestamp.
     */
    public function getLastTriggeredAt(string $serverKey, string $channel): ?DateTimeImmutable
    {
        $path = $this->lockFilePath($serverKey, $channel);
        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $timestamp = (int) trim($contents);
        if ($timestamp <= 0) {
            return null;
        }

        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        if (@mkdir($path, 0777, true) || is_dir($path)) {
            return true;
        }

        $this->logger->warning("Failed to create lock directory [directory={$path}]");

        return false;
    }

    private function lockFilePath(string $serverKey, string $channel): string
    {
        $serverSlug = $this->slugify($serverKey ?: 'unknown-server');
        $channelSlug = $this->slugify($channel ?: 'unknown-channel');

        return $this->baseDirectory
            . DIRECTORY_SEPARATOR . $serverSlug
            . DIRECTORY_SEPARATOR . $channelSlug . '.lock';
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? 'value';

        return trim($value, '-') ?: 'value';
    }
}
