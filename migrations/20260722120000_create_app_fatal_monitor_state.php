<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAppFatalMonitorState extends AbstractMigration
{
    public function change(): void
    {
        $this->table('app_fatal_monitor_state', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('server_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('application_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('fatal_cursor', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('fatal_count', 'integer', ['null' => false, 'signed' => false])
            ->addTimestamps()
            ->addIndex(['server_id', 'application_id'], ['unique' => true, 'name' => 'uq_app_fatal_monitor_state'])
            ->addForeignKey('server_id', 'servers', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('application_id', 'applications', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
