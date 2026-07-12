<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RenameFatalEventType extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE events SET type = 'fatal_errors_occurring' WHERE type = 'fatal_errors_occuring'");
    }

    /**
     * Lossy: renames all correctly spelled rows, including rows created after up().
     */
    public function down(): void
    {
        $this->execute("UPDATE events SET type = 'fatal_errors_occuring' WHERE type = 'fatal_errors_occurring'");
    }
}
