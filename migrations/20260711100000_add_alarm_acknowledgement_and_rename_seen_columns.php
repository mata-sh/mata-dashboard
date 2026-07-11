<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Alarm acknowledgement + column semantics cleanup.
 *
 * - Adds user-facing acknowledgement columns to `alarms` (the notification
 *   bell and unacknowledged filtering are driven by `acknowledged_at IS NULL`).
 * - Renames the two machine-bookkeeping `seen_at` columns so they can no
 *   longer be mistaken for user-facing seen state:
 *   - `events.seen_at`  -> `evaluated_at`  (alert-decision cron marker)
 *   - `alarms.seen_at`  -> `processed_at`  (dispatcher activity timestamp,
 *     also feeds email throttling counts)
 */
final class AddAlarmAcknowledgementAndRenameSeenColumns extends AbstractMigration
{
    public function change(): void
    {
        $alarms = $this->table('alarms');

        if (!$alarms->hasColumn('acknowledged_at')) {
            $alarms->addColumn('acknowledged_at', 'datetime', [
                'null' => true,
                'comment' => 'When a user acknowledged this alarm in the dashboard',
            ]);
        }

        if (!$alarms->hasColumn('acknowledged_by')) {
            $alarms->addColumn('acknowledged_by', 'string', [
                'limit' => 50,
                'null' => true,
                'comment' => 'Username that acknowledged the alarm',
            ]);
        }

        if (!$alarms->hasIndex(['acknowledged_at'])) {
            $alarms->addIndex(['acknowledged_at'], ['name' => 'idx_acknowledged_at']);
        }

        if ($alarms->hasColumn('seen_at')) {
            $alarms->renameColumn('seen_at', 'processed_at');
        }

        $alarms->update();

        $events = $this->table('events');

        if ($events->hasColumn('seen_at')) {
            $events->renameColumn('seen_at', 'evaluated_at');
        }

        $events->update();
    }
}
