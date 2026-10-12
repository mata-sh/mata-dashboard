<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Config loader event types follow one scheme: `<file>_config_invalid` for a rejected file
 * (formerly `_not_found`, although it also covered malformed and empty files) and
 * `<file>_config_invalid_entry` for a skipped entry (formerly `_validation_error`).
 */
final class RenameConfigLoadEventTypes extends AbstractMigration
{
    private const RENAMES = [
        'ttl_config_not_found' => 'ttl_config_invalid',
        'intervals_config_not_found' => 'intervals_config_invalid',
        'data_retention_config_not_found' => 'data_retention_config_invalid',
        'thresholds_config_not_found' => 'thresholds_config_invalid',
        'probes_config_not_found' => 'probes_config_invalid',
        'servers_config_not_found' => 'servers_config_invalid',
        'thresholds_config_validation_error' => 'thresholds_config_invalid_entry',
        'servers_config_validation_error' => 'servers_config_invalid_entry',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->execute("UPDATE events SET type = '{$new}' WHERE type = '{$old}'");
        }
    }

    /**
     * Lossy: renames all rows with the new types, including rows created after up().
     */
    public function down(): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->execute("UPDATE events SET type = '{$old}' WHERE type = '{$new}'");
        }
    }
}
