<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class OptimizeEventLogAlarmEvaluation extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('dashboard_event_log');

        // Add composite index for efficient cronjob queries
        // Optimizes: WHERE seen_at IS NULL AND level IN (...)  ORDER BY datetime ASC
        if (!$table->hasIndex(['seen_at', 'datetime', 'level'])) {
            $table->addIndex(['seen_at', 'datetime', 'level'], [
                'name' => 'idx_event_alarm_eval',
            ]);
        }

        $table->update();
    }
}
