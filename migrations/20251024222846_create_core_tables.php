<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCoreTables extends AbstractMigration
{
    public function change(): void
    {
        $this->createEnvironmentsTable();
        $this->createServersTable();
        $this->createApplicationsTable();
        $this->createServerApplicationsTable();
        $this->createMetricCollectorsTable();
        $this->createConfigSyncTrackingTable();
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

    private function createEnvironmentsTable(): void
    {
        $table = $this->table('environments', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addTimestamps()
            ->addIndex(['name'], ['unique' => true, 'name' => 'uq_environment_name'])
            ->create();
    }

    private function createServersTable(): void
    {
        $table = $this->table('servers', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('environment_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('hostname', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('provider', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('metadata', 'json', [
                'null' => true,
                'comment' => 'Additional server configuration from config files (api keys, theming, custom fields, etc.)',
            ])
            ->addTimestamps()
            ->addIndex(['name'], ['unique' => true, 'name' => 'uq_server_name'])
            ->addForeignKey('environment_id', 'environments', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_servers_environment',
            ])
            ->create();
    }

    private function createApplicationsTable(): void
    {
        $table = $this->table('applications', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('code', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('display_name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('app_env', 'string', ['limit' => 10, 'null' => true])
            ->addColumn('owner', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('metadata', 'json', [
                'null' => true,
                'comment' => 'Additional application configuration from config files (documentation, tech stack, criticality, contacts, etc.)',
            ])
            ->addTimestamps()
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_application_code'])
            ->create();
    }

    private function createServerApplicationsTable(): void
    {
        $table = $this->table('server_applications', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('server_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('application_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('deployment_tag', 'string', [
                'limit' => 50,
                'null' => true,
                'comment' => 'Optional environment/app variant',
            ])
            ->addTimestamps()
            ->addIndex(['server_id', 'application_id', 'deployment_tag'], [
                'unique' => true,
                'name' => 'uq_server_application',
            ])
            ->addIndex(['application_id'], ['name' => 'idx_application'])
            ->addForeignKey('server_id', 'servers', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_server_app_server',
            ])
            ->addForeignKey('application_id', 'applications', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_server_app_application',
            ])
            ->create();
    }

    private function createMetricCollectorsTable(): void
    {
        $table = $this->table('metric_collectors', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('version', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('contact', 'string', ['limit' => 100, 'null' => true])
            ->addTimestamps()
            ->addIndex(['name'], ['unique' => true, 'name' => 'uq_collector_name'])
            ->create();
    }

    private function createConfigSyncTrackingTable(): void
    {
        $table = $this->table('config_sync_tracking', $this->tableOptions());
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('config_file_path', 'string', [
                'limit' => 255,
                'null' => false,
                'comment' => 'Absolute path to the configuration file being tracked',
            ])
            ->addColumn('file_modified_at', 'timestamp', [
                'null' => false,
                'comment' => 'Filesystem modification timestamp of the config file',
            ])
            ->addColumn('last_synced_at', 'timestamp', [
                'null' => false,
                'comment' => 'When the file was last successfully synced to database',
            ])
            ->addColumn('sync_status', 'enum', [
                'values' => ['success', 'failed'],
                'null' => false,
                'comment' => 'Result of the last sync operation',
            ])
            ->addColumn('sync_details', 'json', [
                'null' => true,
                'comment' => 'Additional sync metadata (servers synced, errors, etc.)',
            ])
            ->addTimestamps()
            ->addIndex(['config_file_path'], [
                'unique' => true,
                'name' => 'uq_config_file_path',
            ])
            ->create();
    }
}
