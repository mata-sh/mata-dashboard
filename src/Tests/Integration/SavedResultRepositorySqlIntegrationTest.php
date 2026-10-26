<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Models\SavedResult\SavedResultRepository;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;

/**
 * @internal
 *
 * @covers \Mata\Models\SavedResult\SavedResultRepository
 */
final class SavedResultRepositorySqlIntegrationTest extends TestCase
{
    /** An agent searching for "disk_full" or "100%" must not get every saved result back. */
    public function testSearchMatchesLikeWildcardsLiterally(): void
    {
        $db = new SqliteTestDatabase();
        $db->pdo()->exec(<<<'SQL'
            CREATE TABLE cli_saved_results (
                id INTEGER PRIMARY KEY,
                command TEXT NOT NULL,
                arguments TEXT NOT NULL,
                server TEXT,
                app TEXT,
                label TEXT NOT NULL,
                note TEXT,
                status TEXT NOT NULL,
                document TEXT NOT NULL,
                created_at TEXT NOT NULL
            );
            SQL);
        $repository = new SavedResultRepository($db);
        $full = $repository->save('events:list', [], 'web-01', null, 'incident-42', 'disk_full at 100%', 'ok', '{}');
        $repository->save('logs:show', ['--limit' => '5'], 'web-01', 'shop', 'diskXfull', 'at 1000', 'ok', '{}');

        foreach (['disk_full', '100%'] as $text) {
            $found = $repository->search(null, null, null, null, $text, null, 10);
            $this->assertSame([$full], array_column($found, 'id'), $text);
        }
    }
}
