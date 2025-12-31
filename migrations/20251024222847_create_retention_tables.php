<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

final class CreateRetentionTables extends AbstractMigration
{
    public function up(): void
    {
        $this->createServerMetricsRawTable();
        $this->createServerMetricsHourlyTable();
        $this->createServerMetricsDailyTable();
        $this->createAppMetricsRawTable();
        $this->createAppMetricsHourlyTable();
        $this->createAppMetricsDailyTable();
    }

    public function down(): void
    {
        $this->table('app_metrics_daily')->drop()->save();
        $this->table('app_metrics_hourly')->drop()->save();
        $this->table('app_metrics_raw')->drop()->save();
        $this->table('server_metrics_daily')->drop()->save();
        $this->table('server_metrics_hourly')->drop()->save();
        $this->table('server_metrics_raw')->drop()->save();
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


    private function createServerMetricsRawTable(): void
    {
        $tableOptions = array_merge(
            $this->tableOptions(),
            ['primary_key' => ['id', 'collected_at']]
        );

        $table = $this->table('server_metrics_raw', $tableOptions);
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('server_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('collector_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('collected_at', 'datetime', ['null' => false, 'precision' => 3])
            ->addColumn('ingested_at', 'datetime', [
                'null' => false,
                'precision' => 3,
                'default' => Literal::from('CURRENT_TIMESTAMP(3)'),
            ])
            ->addColumn('payload_hash', 'binary', ['limit' => 16, 'null' => false])
            ->addColumn('cpu_usage', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('ram_used', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('ram_total', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('disk_used', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('disk_total', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('ram_usage_percent', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('disk_usage_percent', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('total_file_count', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('process_count', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('php_process_count', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('php_version', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('os_version', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('server_uptime', 'biginteger', ['null' => true, 'signed' => false])
            ->addIndex(['server_id', 'collector_id', 'collected_at'], [
                'unique' => true,
                'name' => 'uq_server_collect_time',
            ])
            ->addIndex(['server_id', 'payload_hash', 'collected_at'], ['unique' => true, 'name' => 'uq_server_payload'])
            ->addIndex(['server_id', 'collected_at'], ['name' => 'idx_server_time'])
            ->addIndex(['collected_at'], ['name' => 'idx_collected_at'])
            ->addIndex(['ingested_at'], ['name' => 'idx_ingested_at'])
            // Foreign keys omitted: MySQL does not support FK constraints on partitioned tables.
            ->create();

        // Create minimal partition structure: initial partition + MAXVALUE
        // Manage monthly partitions via separate maintenance script
        $this->execute(
            <<<'SQL'
ALTER TABLE server_metrics_raw
PARTITION BY RANGE (TO_DAYS(collected_at)) (
    PARTITION p_init VALUES LESS THAN (TO_DAYS('2000-01-01')),
    PARTITION pmax VALUES LESS THAN MAXVALUE
)
SQL
        );

        // Add CHECK constraints for percentage fields
        $this->execute(
            <<<'SQL'
ALTER TABLE server_metrics_raw
    ADD CONSTRAINT chk_server_cpu_usage CHECK (cpu_usage IS NULL OR (cpu_usage >= 0 AND cpu_usage <= 100)),
    ADD CONSTRAINT chk_server_ram_percent CHECK (ram_usage_percent IS NULL OR (ram_usage_percent >= 0 AND ram_usage_percent <= 100)),
    ADD CONSTRAINT chk_server_disk_percent CHECK (disk_usage_percent IS NULL OR (disk_usage_percent >= 0 AND disk_usage_percent <= 100))
SQL
        );
    }

    private function createServerMetricsHourlyTable(): void
    {
        $table = $this->table('server_metrics_hourly', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('server_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('hour_timestamp', 'datetime', [
                'null' => false,
                'comment' => 'Start of the hour',
            ])
            ->addColumn('cpu_avg', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('cpu_min', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('cpu_max', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('ram_used_avg', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('ram_used_min', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('ram_used_max', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('ram_percent_avg', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('ram_percent_max', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('disk_used_avg', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('disk_used_min', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('disk_used_max', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('disk_percent_avg', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('disk_percent_max', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('file_count_avg', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('process_count_avg', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('process_count_max', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('sample_count', 'integer', ['default' => 0, 'null' => false, 'signed' => false])
            ->addColumn('first_sample', 'datetime', ['null' => true])
            ->addColumn('last_sample', 'datetime', ['null' => true])
            ->addIndex(['server_id', 'hour_timestamp'], ['unique' => true, 'name' => 'uq_server_hour'])
            ->addIndex(['hour_timestamp'], ['name' => 'idx_hour_timestamp'])
            ->addForeignKey('server_id', 'servers', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_server_hourly_server',
            ])
            ->create();

        // Add CHECK constraints for percentage fields
        $this->execute(
            <<<'SQL'
ALTER TABLE server_metrics_hourly
    ADD CONSTRAINT chk_server_hourly_cpu_avg CHECK (cpu_avg IS NULL OR (cpu_avg >= 0 AND cpu_avg <= 100)),
    ADD CONSTRAINT chk_server_hourly_cpu_min CHECK (cpu_min IS NULL OR (cpu_min >= 0 AND cpu_min <= 100)),
    ADD CONSTRAINT chk_server_hourly_cpu_max CHECK (cpu_max IS NULL OR (cpu_max >= 0 AND cpu_max <= 100)),
    ADD CONSTRAINT chk_server_hourly_ram_percent_avg CHECK (ram_percent_avg IS NULL OR (ram_percent_avg >= 0 AND ram_percent_avg <= 100)),
    ADD CONSTRAINT chk_server_hourly_ram_percent_max CHECK (ram_percent_max IS NULL OR (ram_percent_max >= 0 AND ram_percent_max <= 100)),
    ADD CONSTRAINT chk_server_hourly_disk_percent_avg CHECK (disk_percent_avg IS NULL OR (disk_percent_avg >= 0 AND disk_percent_avg <= 100)),
    ADD CONSTRAINT chk_server_hourly_disk_percent_max CHECK (disk_percent_max IS NULL OR (disk_percent_max >= 0 AND disk_percent_max <= 100))
SQL
        );
    }

    private function createServerMetricsDailyTable(): void
    {
        $table = $this->table('server_metrics_daily', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('server_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('date', 'date', ['null' => false])
            ->addColumn('cpu_avg', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('cpu_min', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('cpu_max', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('ram_used_avg', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('ram_used_min', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('ram_used_max', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('ram_percent_avg', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('ram_percent_max', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('disk_used_avg', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('disk_used_min', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('disk_used_max', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('disk_percent_avg', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('disk_percent_max', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('file_count_avg', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('file_count_max', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('process_count_avg', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('process_count_max', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('sample_count', 'integer', ['default' => 0, 'null' => false, 'signed' => false])
            ->addColumn('alarm_count', 'integer', ['default' => 0, 'null' => false, 'signed' => false])
            ->addColumn('warning_count', 'integer', ['default' => 0, 'null' => false, 'signed' => false])
            ->addIndex(['server_id', 'date'], ['unique' => true, 'name' => 'uq_server_date'])
            ->addIndex(['date'], ['name' => 'idx_date'])
            ->addForeignKey('server_id', 'servers', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_server_daily_server',
            ])
            ->create();

        // Add CHECK constraints for percentage fields
        $this->execute(
            <<<'SQL'
ALTER TABLE server_metrics_daily
    ADD CONSTRAINT chk_server_daily_cpu_avg CHECK (cpu_avg IS NULL OR (cpu_avg >= 0 AND cpu_avg <= 100)),
    ADD CONSTRAINT chk_server_daily_cpu_min CHECK (cpu_min IS NULL OR (cpu_min >= 0 AND cpu_min <= 100)),
    ADD CONSTRAINT chk_server_daily_cpu_max CHECK (cpu_max IS NULL OR (cpu_max >= 0 AND cpu_max <= 100)),
    ADD CONSTRAINT chk_server_daily_ram_percent_avg CHECK (ram_percent_avg IS NULL OR (ram_percent_avg >= 0 AND ram_percent_avg <= 100)),
    ADD CONSTRAINT chk_server_daily_ram_percent_max CHECK (ram_percent_max IS NULL OR (ram_percent_max >= 0 AND ram_percent_max <= 100)),
    ADD CONSTRAINT chk_server_daily_disk_percent_avg CHECK (disk_percent_avg IS NULL OR (disk_percent_avg >= 0 AND disk_percent_avg <= 100)),
    ADD CONSTRAINT chk_server_daily_disk_percent_max CHECK (disk_percent_max IS NULL OR (disk_percent_max >= 0 AND disk_percent_max <= 100))
SQL
        );
    }

    private function createAppMetricsRawTable(): void
    {
        $tableOptions = array_merge(
            $this->tableOptions(),
            ['primary_key' => ['id', 'collected_at']]
        );

        $table = $this->table('app_metrics_raw', $tableOptions);
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('server_application_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('collector_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('collected_at', 'datetime', ['null' => false, 'precision' => 3])
            ->addColumn('ingested_at', 'datetime', [
                'null' => false,
                'precision' => 3,
                'default' => Literal::from('CURRENT_TIMESTAMP(3)'),
            ])
            ->addColumn('payload_hash', 'binary', ['limit' => 16, 'null' => false])
            ->addColumn('status', 'enum', [
                'values' => ['up', 'down', 'degraded'],
                'null' => true,
            ])
            ->addColumn('response_time_ms', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('session_count', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('session_count_last', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('log_file_size', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('error_count', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('warning_count', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('db_connection_count', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['server_application_id', 'collector_id', 'collected_at'], [
                'unique' => true,
                'name' => 'uq_app_collect_time',
            ])
            ->addIndex(['server_application_id', 'payload_hash', 'collected_at'], [
                'unique' => true,
                'name' => 'uq_app_payload',
            ])
            ->addIndex(['server_application_id', 'collected_at'], ['name' => 'idx_app_time'])
            ->addIndex(['collected_at'], ['name' => 'idx_collected_at'])
            ->addIndex(['ingested_at'], ['name' => 'idx_ingested_at'])
            ->addIndex(['status', 'collected_at'], ['name' => 'idx_status_time'])
            // Foreign keys omitted: MySQL does not support FK constraints on partitioned tables.
            ->create();

        // Create minimal partition structure: initial partition + MAXVALUE
        // Manage monthly partitions via separate maintenance script
        $this->execute(
            <<<'SQL'
ALTER TABLE app_metrics_raw
PARTITION BY RANGE (TO_DAYS(collected_at)) (
    PARTITION p_init VALUES LESS THAN (TO_DAYS('2000-01-01')),
    PARTITION pmax VALUES LESS THAN MAXVALUE
)
SQL
        );
    }

    private function createAppMetricsHourlyTable(): void
    {
        $table = $this->table('app_metrics_hourly', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('server_application_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('hour_timestamp', 'datetime', ['null' => false])
            ->addColumn('session_count_avg', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('session_count_min', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('session_count_max', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('response_time_avg', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('response_time_min', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('response_time_max', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('log_file_size_avg', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('error_count_total', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('warning_count_total', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('uptime_percent', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('sample_count', 'integer', ['default' => 0, 'null' => false, 'signed' => false])
            ->addColumn('first_sample', 'datetime', ['null' => true])
            ->addColumn('last_sample', 'datetime', ['null' => true])
            ->addIndex(['server_application_id', 'hour_timestamp'], [
                'unique' => true,
                'name' => 'uq_app_hour',
            ])
            ->addIndex(['hour_timestamp'], ['name' => 'idx_hour_timestamp'])
            ->addForeignKey('server_application_id', 'server_applications', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_app_hourly_server_app',
            ])
            ->create();

        // Add CHECK constraints for percentage fields
        $this->execute(
            <<<'SQL'
ALTER TABLE app_metrics_hourly
    ADD CONSTRAINT chk_app_hourly_uptime_percent CHECK (uptime_percent IS NULL OR (uptime_percent >= 0 AND uptime_percent <= 100))
SQL
        );
    }

    private function createAppMetricsDailyTable(): void
    {
        $table = $this->table('app_metrics_daily', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('server_application_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('date', 'date', ['null' => false])
            ->addColumn('session_count_avg', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('session_count_min', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('session_count_max', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('response_time_avg', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('response_time_p95', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('error_count_total', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('warning_count_total', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('uptime_percent', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('downtime_minutes', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('sample_count', 'integer', ['default' => 0, 'null' => false, 'signed' => false])
            ->addColumn('alarm_count', 'integer', ['default' => 0, 'null' => false, 'signed' => false])
            ->addIndex(['server_application_id', 'date'], ['unique' => true, 'name' => 'uq_app_date'])
            ->addIndex(['date'], ['name' => 'idx_date'])
            ->addForeignKey('server_application_id', 'server_applications', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_app_daily_server_app',
            ])
            ->create();

        // Add CHECK constraints for percentage fields
        $this->execute(
            <<<'SQL'
ALTER TABLE app_metrics_daily
    ADD CONSTRAINT chk_app_daily_uptime_percent CHECK (uptime_percent IS NULL OR (uptime_percent >= 0 AND uptime_percent <= 100))
SQL
        );
    }
}
