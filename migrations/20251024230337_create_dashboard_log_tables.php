<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateDashboardLogTables extends AbstractMigration
{
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

    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {

        $eventLog = $this->table('dashboard_event_log', $this->tableOptions());

        $eventLog
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
            ->addColumn('alarm_channel', 'enum', ['values' => ['email', 'log', 'sms'], 'null' => true])
            ->addColumn('seen_at', 'datetime', ['null' => true])
            ->addIndex(['server_id', 'type', 'datetime'], ['name' => 'idx_lookup'])
            ->addIndex(['server_id', 'application_id', 'datetime'], ['name' => 'idx_server_app_time'])
            ->addIndex(['server_id'], ['name' => 'idx_server_id'])
            ->addIndex(['application_id'], ['name' => 'idx_application_id'])
            ->addIndex(['datetime'], ['name' => 'idx_datetime'])
            ->addIndex(['seen_at'], ['name' => 'idx_seen_at'])
            ->addIndex(['alarm_channel'], ['name' => 'idx_alarm_channel'])
            ->addIndex(['level'], ['name' => 'idx_level'])
            // ->addIndex(['alarm_id'], ['name' => 'fk_alarm_id'])
            // ->addForeignKey('alarm_id', 'dashboard_alarm_log', 'id', [
            //     'delete' => 'SET_NULL',
            //     'update' => 'NO_ACTION',
            //     'constraint' => 'fk_event_log_alarm',
            // ])
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

        $alarmLog = $this->table('dashboard_alarm_log', $this->tableOptions());

        $alarmLog
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('event_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('channel', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('status', 'enum', ['values' => ['pending', 'sent', 'failed', 'throttled'], 'null' => false])
            ->addColumn('seen_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addIndex(['channel'], ['name' => 'idx_channel'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['seen_at'], ['name' => 'idx_seen_at'])
            ->addIndex(['created_at'], ['name' => 'idx_created_at'])
            ->addForeignKey('event_id', 'dashboard_event_log', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_event_log_alarm',
            ])
            ->create();


        $cooldowns = $this->table('dashboard_cooldowns', $this->tableOptions());

        $cooldowns
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('cooldown_hash', 'string', [
                'limit' => 64,
                'null' => false,
                'comment' => 'SHA256 hash of type|server|app|context|channel - events use empty channel, alarms include channel name',
            ])
            ->addColumn('cooldown_until', 'datetime', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['cooldown_hash'], ['name' => 'idx_cooldown_hash', 'unique' => true])
            ->addIndex(['cooldown_until'], ['name' => 'idx_cooldown_until'])
            ->create();

        $loginLog = $this->table('dashboard_login_log', $this->tableOptions());

        $loginLog
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('user', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false])
            ->addColumn('useragent', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('datetime', 'datetime', ['null' => false])
            ->addIndex(['user', 'datetime'], ['name' => 'idx_user_datetime'])
            ->addIndex(['datetime'], ['name' => 'idx_datetime'])
            ->addIndex(['ip'], ['name' => 'idx_ip'])
            ->create();

        $notifications = $this->table('dashboard_notifications', $this->tableOptions());

        $notifications
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
            ->addColumn('read_at', 'datetime', ['null' => true])
            ->addColumn('deactivated_at', 'datetime', ['null' => true])
            ->addIndex(['deactivated_at', 'last_shown', 'show_interval'], ['name' => 'idx_active_notifications'])
            ->addIndex(['type', 'read_at'], ['name' => 'idx_type_read'])
            ->addIndex(['identifier'], ['name' => 'idx_identifier'])
            ->addIndex(['created_at'], ['name' => 'idx_created_at'])
            ->create();
    }
}
