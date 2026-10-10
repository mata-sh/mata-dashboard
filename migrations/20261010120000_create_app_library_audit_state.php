<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAppLibraryAuditState extends AbstractMigration
{
    public function change(): void
    {
        $this->table('app_library_audit_state', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('server_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('application_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('advisory_ids', 'text', ['null' => false])
            ->addTimestamps()
            ->addIndex(['server_id', 'application_id'], ['unique' => true, 'name' => 'uq_app_library_audit_state'])
            ->addForeignKey('server_id', 'servers', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('application_id', 'applications', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
