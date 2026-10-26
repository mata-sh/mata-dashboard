<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * JSON documents of read-only bin/mata commands run with --save, so an agent can find what it
 * looked at later by label, command, server or text. Saved on purpose, so retention keeps them.
 */
final class CreateCliSavedResults extends AbstractMigration
{
    public function change(): void
    {
        $this->table('cli_saved_results', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('command', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('arguments', 'text', ['null' => false])
            ->addColumn('server', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('app', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('label', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('note', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('document', 'text', ['limit' => 16777215, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['label'], ['name' => 'idx_cli_saved_results_label'])
            ->addIndex(['created_at'], ['name' => 'idx_cli_saved_results_created'])
            ->addIndex(['server', 'created_at'], ['name' => 'idx_cli_saved_results_server'])
            ->create();
    }
}
