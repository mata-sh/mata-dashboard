<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateDashboardLogTables extends AbstractMigration
{
    public function up(): void
    {
        $this->createEventsTable();
        $this->createAlarmsTable();
        $this->createAlarmDeliveriesTable();
        $this->createCooldownsTable();
        $this->createLoginsTable();
        $this->createNotificationsTable();
    }

    public function down(): void
    {
        $this->table('notifications')->drop()->save();
        $this->table('logins')->drop()->save();
        $this->table('cooldowns')->drop()->save();
        $this->table('alarm_deliveries')->drop()->save();
        $this->table('alarms')->drop()->save();
        $this->table('events')->drop()->save();
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

    private function createEventsTable(): void
    {
        $this->table('events', $this->tableOptions())
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('server_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('application_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('type', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('message', 'text', ['null' => false])
            ->addColumn('context', 'text', [
                'null' => true,
                'comment' => 'JSON serialized context data: {metric?: string, threshold?: number, actual_value?: number, additional_data?: object}',
            ])
            ->addColumn('level', 'enum', ['values' => ['info', 'warning', 'error', 'critical'], 'null' => false])
            ->addColumn('datetime', 'datetime', ['null' => false])
            ->addColumn('evaluated_at', 'datetime', ['null' => true])
            ->addIndex(['server_id', 'type', 'datetime'], ['name' => 'idx_lookup'])
            ->addIndex(['server_id', 'application_id', 'datetime'], ['name' => 'idx_server_app_time'])
            ->addIndex(['server_id'], ['name' => 'idx_server_id'])
            ->addIndex(['application_id'], ['name' => 'idx_application_id'])
            ->addIndex(['datetime'], ['name' => 'idx_datetime'])
            ->addIndex(['evaluated_at'], ['name' => 'idx_events_evaluated_at'])
            ->addIndex(['level'], ['name' => 'idx_level'])
            ->addIndex(['evaluated_at', 'datetime', 'level'], ['name' => 'idx_events_alarm_evaluation'])
            ->addForeignKey('server_id', 'servers', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_event_log_server',
            ])
            ->addForeignKey('application_id', 'applications', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_event_log_application',
            ])
            ->create();
    }

    private function createAlarmsTable(): void
    {
        $this->table('alarms', $this->tableOptions())
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('event_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('acknowledged_at', 'datetime', ['null' => true])
            ->addColumn('acknowledged_by', 'string', ['limit' => 50, 'null' => true])
            ->addIndex(['event_id'], ['name' => 'uq_alarms_event_id', 'unique' => true])
            ->addIndex(['created_at'], ['name' => 'idx_alarms_created_at'])
            ->addIndex(['acknowledged_at'], ['name' => 'idx_alarms_acknowledged_at'])
            ->addForeignKey('event_id', 'events', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_alarms_event',
            ])
            ->create();
    }

    private function createAlarmDeliveriesTable(): void
    {
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

    private function createCooldownsTable(): void
    {
        $this->table('cooldowns', $this->tableOptions())
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('cooldown_hash', 'string', [
                'limit' => 64,
                'null' => false,
                'comment' => 'SHA256 hash of type|server|app|context|channel',
            ])
            ->addColumn('cooldown_until', 'datetime', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['cooldown_hash'], ['name' => 'idx_cooldown_hash', 'unique' => true])
            ->addIndex(['cooldown_until'], ['name' => 'idx_cooldown_until'])
            ->create();
    }

    private function createLoginsTable(): void
    {
        $this->table('logins', $this->tableOptions())
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('user', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false])
            ->addColumn('useragent', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('datetime', 'datetime', ['null' => false])
            ->addIndex(['user', 'datetime'], ['name' => 'idx_user_datetime'])
            ->addIndex(['datetime'], ['name' => 'idx_datetime'])
            ->addIndex(['ip'], ['name' => 'idx_ip'])
            ->create();
    }

    private function createNotificationsTable(): void
    {
        $this->table('notifications', $this->tableOptions())
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('identifier', 'string', [
                'limit' => 100,
                'null' => true,
                'comment' => 'Unique identifier for persistent notifications (enforce uniqueness at application level for active notifications)',
            ])
            ->addColumn('type', 'enum', [
                'values' => ['info', 'warning', 'error', 'alarm'],
                'null' => false,
            ])
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('message', 'text', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('last_shown', 'datetime', ['null' => true])
            ->addColumn('show_interval', 'integer', [
                'null' => false,
                'default' => 24,
                'comment' => 'Hours between displays, 0 = show once',
            ])
            ->addColumn('dismissed_at', 'datetime', ['null' => true, 'comment' => 'When user closed/dismissed the notification'])
            ->addColumn('deactivated_at', 'datetime', ['null' => true, 'comment' => 'When user permanently disabled this notification'])
            ->addIndex(['deactivated_at', 'dismissed_at', 'show_interval'], ['name' => 'idx_active_notifications'])
            ->addIndex(['type', 'dismissed_at'], ['name' => 'idx_type_dismissed'])
            ->addIndex(['identifier'], ['name' => 'idx_identifier'])
            ->addIndex(['created_at'], ['name' => 'idx_created_at'])
            ->create();
    }
}
