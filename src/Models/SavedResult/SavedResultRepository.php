<?php

declare(strict_types=1);

namespace Mata\Models\SavedResult;

use DateTimeImmutable;
use Mata\Database\DatabaseInterface;

/** JSON documents of bin/mata commands run with --save. */
final class SavedResultRepository
{
    /** The fields of a list row; the document is only read by find(). */
    private const SUMMARY_COLUMNS = 'id, created_at, command, arguments, server, app, label, note, status';

    public function __construct(private DatabaseInterface $db) {}

    /** @param array<string,mixed> $arguments the arguments and options the command ran with */
    public function save(
        string $command,
        array $arguments,
        ?string $server,
        ?string $app,
        string $label,
        ?string $note,
        string $status,
        string $document,
    ): int {
        return $this->db->runInsert(
            'INSERT INTO cli_saved_results (command, arguments, server, app, label, note, status, document, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [$command, json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $server, $app, $label, $note, $status, $document],
        );
    }

    /**
     * Newest first, without the documents. $text matches the label, the note and the document.
     *
     * @return list<array<string,mixed>>
     */
    public function search(
        ?string $label,
        ?string $command,
        ?string $server,
        ?string $app,
        ?string $text,
        ?DateTimeImmutable $since,
        int $limit,
    ): array {
        $limit = max(1, $limit);
        $conditions = [];
        $params = [];
        foreach (['label' => $label, 'command' => $command, 'server' => $server, 'app' => $app] as $column => $value) {
            if ($value !== null) {
                $conditions[] = "{$column} = ?";
                $params[] = $value;
            }
        }
        if ($text !== null) {
            // '!' as the LIKE escape character works the same in MySQL and SQLite.
            $pattern = '%' . strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $conditions[] = "(label LIKE ? ESCAPE '!' OR note LIKE ? ESCAPE '!' OR document LIKE ? ESCAPE '!')";
            array_push($params, $pattern, $pattern, $pattern);
        }
        if ($since !== null) {
            $conditions[] = 'created_at >= ?';
            $params[] = $since->format('Y-m-d H:i:s');
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $columns = self::SUMMARY_COLUMNS;

        $rows = $this->db->runSelect(<<<SQL
            SELECT {$columns}
            FROM cli_saved_results
            {$where}
            ORDER BY created_at DESC, id DESC
            LIMIT {$limit}
            SQL, $params);

        return array_values(array_map(self::summary(...), $rows));
    }

    /**
     * One saved result with its document; null when there is none.
     *
     * @return null|array<string,mixed>
     */
    public function find(int $id): ?array
    {
        $columns = self::SUMMARY_COLUMNS;
        $row = $this->db->runSelect("SELECT {$columns}, document FROM cli_saved_results WHERE id = ?", [$id])[0] ?? null;
        if ($row === null) {
            return null;
        }
        $document = json_decode((string) $row['document'], true);

        return self::summary($row) + ['document' => is_array($document) ? $document : null];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private static function summary(array $row): array
    {
        $arguments = json_decode((string) $row['arguments'], true);

        return [
            'id' => (int) $row['id'],
            'created_at' => (string) $row['created_at'],
            'command' => (string) $row['command'],
            // An object even when empty, so `arguments` keeps one JSON type.
            'arguments' => (object) (is_array($arguments) ? $arguments : []),
            'server' => $row['server'],
            'app' => $row['app'],
            'label' => (string) $row['label'],
            'note' => $row['note'],
            'status' => (string) $row['status'],
        ];
    }
}
