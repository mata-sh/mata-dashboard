<?php

declare(strict_types=1);

namespace Mata\Services\Cron;

use InvalidArgumentException;
use RuntimeException;

/**
 * Appends heartbeat records and compacts a file once it would exceed its maximum size.
 *
 * Readers and writers lock the heartbeat itself, so compaction rewrites it in
 * place and its inode never changes.
 */
final class CronHeartbeatWriter
{
    /** Bytes holding the first record, kept by compaction and read by CronHeartbeatReader; one record is about 100 bytes. */
    public const HEAD_BYTES = 1024;

    /** Recent history kept by compaction. */
    public const RETAIN_BYTES = 65_536;

    public function __construct(
        private readonly int $maxBytes,
    ) {
        // A compacted file must fit, or every append would compact again.
        if ($maxBytes <= self::HEAD_BYTES + self::RETAIN_BYTES) {
            throw new InvalidArgumentException('Heartbeat maximum size must exceed the history kept by compaction.');
        }
    }

    /** @param array<string,mixed> $record */
    public function append(string $path, array $record): void
    {
        $content = json_encode($record, JSON_THROW_ON_ERROR) . "\n";

        // Never rename: a writer waiting on the old inode would otherwise lose its append.
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException("Cannot open heartbeat: {$path}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Cannot lock heartbeat: {$path}");
            }
            $size = (int) (fstat($handle)['size'] ?? 0);
            if ($size + strlen($content) > $this->maxBytes) {
                $content = $this->compactedHistory($handle, $size, $path) . $content;
                rewind($handle);
            } else {
                fseek($handle, 0, SEEK_END);
            }
            $this->writeAndTruncate($handle, $content, $path);
        } finally {
            fclose($handle);
        }
    }

    /**
     * First record plus the complete records of the latest RETAIN_BYTES.
     *
     * @param resource $handle
     */
    private function compactedHistory($handle, int $size, string $path): string
    {
        $first = $this->records((string) fread($handle, self::HEAD_BYTES))[0] ?? null;
        if ($first === null) {
            throw new RuntimeException("Cannot preserve first heartbeat: {$path}");
        }
        fseek($handle, max(0, $size - self::RETAIN_BYTES));
        $recent = $this->records((string) stream_get_contents($handle));

        return implode("\n", [$first, ...$recent]) . "\n";
    }

    /**
     * Writes at the current position and cuts off the rest, so a compacted file keeps no old bytes.
     *
     * @param resource $handle
     */
    private function writeAndTruncate($handle, string $content, string $path): void
    {
        while ($content !== '') {
            $written = fwrite($handle, $content);
            if ($written === false || $written === 0) {
                throw new RuntimeException("Cannot write heartbeat: {$path}");
            }
            $content = substr($content, $written);
        }

        $end = ftell($handle);
        // `$end < 0` never holds at runtime; it narrows ftell()'s int for ftruncate().
        if ($end === false || $end < 0 || !ftruncate($handle, $end) || !fflush($handle)) {
            throw new RuntimeException("Cannot finish heartbeat: {$path}");
        }
    }

    /**
     * Match whole records in both historical pretty JSON and compact JSONL. A tail
     * may start inside a record; only an opening brace at column zero starts one.
     *
     * @return list<string> Records re-encoded as compact JSON lines
     */
    private function records(string $content): array
    {
        preg_match_all('/^\{[^\r\n]*\}|^\{\r?\n.*?^\}/ms', $content, $matches);
        $records = [];
        foreach ($matches[0] as $record) {
            $decoded = json_decode($record, true);
            if (is_array($decoded) && isset($decoded['ts'])) {
                $records[] = json_encode($decoded, JSON_THROW_ON_ERROR);
            }
        }

        return $records;
    }
}
