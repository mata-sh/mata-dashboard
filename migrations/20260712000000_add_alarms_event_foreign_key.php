<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Adds database enforcement for the required logical-alarm event relation. */
final class AddAlarmsEventForeignKey extends AbstractMigration
{
    public function up(): void
    {
        $row = $this->fetchRow(<<<'SQL'
            SELECT COUNT(*) AS orphan_count
            FROM alarms a
            LEFT JOIN events e ON e.id = a.event_id
            WHERE e.id IS NULL
            SQL);
        $orphans = (int) ($row['orphan_count'] ?? 0);

        if ($orphans > 0) {
            throw new RuntimeException("Cannot add fk_alarms_event: {$orphans} orphaned alarms exist");
        }

        $this->table('alarms')
            ->addForeignKey('event_id', 'events', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_alarms_event',
            ])
            ->save();
    }

    public function down(): void
    {
        $this->table('alarms')
            ->dropForeignKey('event_id', 'fk_alarms_event')
            ->save();
    }
}
