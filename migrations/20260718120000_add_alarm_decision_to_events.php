<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAlarmDecisionToEvents extends AbstractMigration
{
    public function change(): void
    {
        $this->table('events')
            ->addColumn('alarm_decision', 'json', [
                'null' => true,
                'default' => null,
                'comment' => 'Alarm evaluation verdict (gates, channels, outcome)',
            ])
            ->update();
    }
}
