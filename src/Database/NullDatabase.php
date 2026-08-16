<?php

declare(strict_types=1);

namespace Mata\Database;

use Psr\Log\LoggerInterface;

/**
 * Null-object database implementation used when the real database is unavailable.
 *
 * All operations are no-ops while still fulfilling the database contract so
 * that downstream services keep running in a degraded, read-only mode.
 */
final class NullDatabase implements DatabaseInterface
{
    private LoggerInterface $logger;
    private bool $unavailableLogged = false;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function runQuery(string $sql, array $params = []): array|int
    {
        $this->logUnavailable('runQuery', [
            'sql' => $this->sanitizeSql($sql),
            'params' => $this->sanitizeParams($params),
        ]);

        return match (SqlQueryClassifier::leadingKeyword($sql)) {
            'INSERT', 'UPDATE', 'DELETE' => 0,
            default => [],
        };
    }

    public function beginTransaction(): void
    {
        $this->logUnavailable('beginTransaction');
    }

    public function commit(): void
    {
        $this->logUnavailable('commit');
    }

    public function rollback(): void
    {
        $this->logUnavailable('rollback');
    }

    private function logUnavailable(string $operation, array $context = []): void
    {
        if (!$this->unavailableLogged) {
            $this->logger->error('Database unavailable; application continues with limited functionality.');
            $this->unavailableLogged = true;
        }

        if (!empty($context)) {
            $this->logger->debug(sprintf('Skipped database operation: %s', $operation), $context);
        } else {
            $this->logger->debug(sprintf('Skipped database operation: %s', $operation));
        }
    }

    /**
     * Keep SQL log lines compact to avoid flooding the log file.
     */
    private function sanitizeSql(string $sql): string
    {
        $trimmed = trim((string) preg_replace('/\s+/', ' ', $sql));

        return substr($trimmed, 0, 200);
    }

    /**
     * @param array<int|string,mixed> $params
     *
     * @return array<int|string,string>
     */
    private function sanitizeParams(array $params): array
    {
        return array_map(static fn (mixed $value): string => get_debug_type($value), $params);
    }
}
