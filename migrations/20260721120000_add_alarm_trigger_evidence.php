<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAlarmTriggerEvidence extends AbstractMigration
{
    public function up(): void
    {
        $this->table('alarms')
            ->addColumn('trigger_evidence', 'json', ['null' => true, 'after' => 'event_id'])
            ->update();
    }

    public function down(): void
    {
        $this->table('alarms')->removeColumn('trigger_evidence')->update();
    }
}
