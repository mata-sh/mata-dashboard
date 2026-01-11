<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpdateDashboardAlarmLogQueue extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('alarms');

        if (!$table->hasColumn('priority')) {
            $table->addColumn('priority', 'integer', [
                'null' => false,
                'default' => 100,
                'signed' => false,
                'comment' => 'Lower value indicates higher dispatch priority',
            ]);
        }

        if (!$table->hasColumn('attempts')) {
            $table->addColumn('attempts', 'integer', [
                'null' => false,
                'default' => 0,
                'signed' => false,
                'comment' => 'Number of dispatch attempts performed',
            ]);
        }

        if (!$table->hasColumn('next_retry_at')) {
            $table->addColumn('next_retry_at', 'datetime', [
                'null' => true,
                'comment' => 'Timestamp determining when the next retry is allowed',
            ]);
        }

        if (!$table->hasColumn('error_payload')) {
            $table->addColumn('error_payload', 'text', [
                'null' => true,
                'comment' => 'Last error message/metadata for troubleshooting failed alerts',
            ]);
        }

        if ($table->hasColumn('status')) {
            $table->changeColumn('status', 'enum', [
                'values' => ['pending', 'processing', 'sent', 'retry', 'failed', 'throttled', 'discarded'],
                'default' => 'pending',
                'null' => false,
            ]);
        }

        if ($table->hasIndex(['status'])) {
            $table->removeIndex(['status']);
        }

        if (!$table->hasIndex(['status', 'created_at'])) {
            $table->addIndex(['status', 'created_at'], [
                'name' => 'idx_status_created_at',
            ]);
        }

        $table->update();
    }
}
