<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

final class CreateHelperTables extends AbstractMigration
{
    public function change(): void
    {
        $this->createThresholdPolicyHistoryTable();
    }

    private function tableOptions(): array
    {
        return [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];
    }

    private function createThresholdPolicyHistoryTable(): void
    {
        $table = $this->table('threshold_policy_history', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('target_type', 'enum', ['values' => ['server', 'application'], 'null' => false])
            ->addColumn('target_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('metric', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('threshold_name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('level', 'enum', ['values' => ['warning', 'alarm'], 'null' => false])
            ->addColumn('comparison', 'string', [
                'limit' => 10,
                'null' => false,
                'comment' => '>, >=, <, <=, etc.',
            ])
            ->addColumn('threshold_value', 'decimal', ['precision' => 12, 'scale' => 4, 'null' => false])
            ->addColumn('effective_from', 'datetime', ['null' => false])
            ->addColumn('effective_to', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', [
                'null' => false,
                'default' => Literal::from('CURRENT_TIMESTAMP'),
            ])
            ->addIndex(['target_type', 'target_id', 'metric', 'effective_from'], [
                'name' => 'idx_threshold_target',
            ])
            ->create();
    }
}
