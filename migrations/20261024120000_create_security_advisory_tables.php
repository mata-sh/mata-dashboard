<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Security advisories polled from the sources in advisories.json, plus per-source poll state.
 * Rows are the de-duplication memory: an advisory is notified only when its row is first
 * inserted, so these tables are never pruned by retention.
 */
final class CreateSecurityAdvisoryTables extends AbstractMigration
{
    public function change(): void
    {
        $options = [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];

        $this->table('advisory_source_state', $options)
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('source_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('baseline_at', 'datetime', ['null' => true])
            ->addColumn('last_polled_at', 'datetime', ['null' => true])
            ->addColumn('last_success_at', 'datetime', ['null' => true])
            ->addColumn('consecutive_failures', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('last_error', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('last_item_count', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('fetch_cursor', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['source_key'], ['unique' => true, 'name' => 'uq_advisory_source_state_source'])
            ->create();

        $this->table('advisories', $options)
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('source_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('external_id', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('link', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('summary', 'text', ['null' => true])
            ->addColumn('severity', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('cvss_score', 'decimal', ['precision' => 3, 'scale' => 1, 'null' => true])
            ->addColumn('cve_ids', 'text', ['null' => false])
            ->addColumn('products', 'text', ['null' => false])
            ->addColumn('matched_keywords', 'text', ['null' => false])
            ->addColumn('relevant', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('baseline', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('first_seen_at', 'datetime', ['null' => false])
            ->addColumn('notified_at', 'datetime', ['null' => true])
            ->addColumn('event_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('review_status', 'string', ['limit' => 16, 'null' => false, 'default' => 'new'])
            ->addColumn('review_note', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('reviewed_by', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('reviewed_at', 'datetime', ['null' => true])
            ->addColumn('raw_format', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('raw', 'text', ['limit' => 16777215, 'null' => false])
            ->addIndex(['source_key', 'external_id'], ['unique' => true, 'name' => 'uq_advisories_source_external'])
            ->addIndex(['first_seen_at'], ['name' => 'idx_advisories_first_seen'])
            ->addIndex(['published_at'], ['name' => 'idx_advisories_published'])
            ->addIndex(['relevant', 'notified_at'], ['name' => 'idx_advisories_pending_notification'])
            ->create();
    }
}
