<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Separates logical policy results from channel-specific delivery attempts.
 *
 * Deployments start with an empty notification database. The prior queue table
 * is therefore replaced instead of being backfilled.
 */
final class SplitLogicalAlarmsFromDeliveries extends AbstractMigration
{
    public function up(): void
    {
        $this->table('alarms')->drop()->save();

        $this->table('alarms', $this->tableOptions())
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('event_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('acknowledged_at', 'datetime', ['null' => true])
            ->addColumn('acknowledged_by', 'string', ['limit' => 50, 'null' => true])
            ->addIndex(['event_id'], ['name' => 'uq_alarms_event_id', 'unique' => true])
            ->addIndex(['created_at'], ['name' => 'idx_alarms_created_at'])
            ->addIndex(['acknowledged_at'], ['name' => 'idx_alarms_acknowledged_at'])
            ->create();

        $this->table('alarm_deliveries', $this->tableOptions())
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('alarm_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('channel', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'processing', 'sent', 'retry', 'failed', 'throttled', 'discarded'],
                'default' => 'pending',
                'null' => false,
            ])
            ->addColumn('priority', 'integer', ['null' => false, 'default' => 100, 'signed' => false])
            ->addColumn('attempts', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('processed_at', 'datetime', ['null' => true])
            ->addColumn('next_retry_at', 'datetime', ['null' => true])
            ->addColumn('error_payload', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['alarm_id', 'channel'], ['name' => 'uq_alarm_deliveries_alarm_channel', 'unique' => true])
            ->addIndex(['alarm_id'], ['name' => 'idx_alarm_deliveries_alarm_id'])
            ->addIndex(['channel'], ['name' => 'idx_alarm_deliveries_channel'])
            ->addIndex(['status', 'created_at'], ['name' => 'idx_alarm_deliveries_pending_claim'])
            ->addIndex(['processed_at'], ['name' => 'idx_alarm_deliveries_processed_at'])
            ->addIndex(['next_retry_at'], ['name' => 'idx_alarm_deliveries_next_retry_at'])
            ->addForeignKey('alarm_id', 'alarms', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_alarm_deliveries_alarm',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('alarm_deliveries')->drop()->save();
        $this->table('alarms')->drop()->save();

        $this->table('alarms', $this->tableOptions())
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('event_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('channel', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'processing', 'sent', 'retry', 'failed', 'throttled', 'discarded'],
                'default' => 'pending',
                'null' => false,
            ])
            ->addColumn('processed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('priority', 'integer', ['null' => false, 'default' => 100, 'signed' => false])
            ->addColumn('attempts', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('next_retry_at', 'datetime', ['null' => true])
            ->addColumn('error_payload', 'text', ['null' => true])
            ->addColumn('acknowledged_at', 'datetime', ['null' => true])
            ->addColumn('acknowledged_by', 'string', ['limit' => 50, 'null' => true])
            ->addIndex(['channel'], ['name' => 'idx_channel'])
            ->addIndex(['status', 'created_at'], ['name' => 'idx_status_created_at'])
            ->addIndex(['processed_at'], ['name' => 'idx_alarms_processed_at'])
            ->addIndex(['created_at'], ['name' => 'idx_created_at'])
            ->addIndex(['acknowledged_at'], ['name' => 'idx_acknowledged_at'])
            ->create();
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
}
