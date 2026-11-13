<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\ConfigSource;
use Mata\Config\Validation\ConfigProblem;
use Mata\Config\Validation\ConfigValidationReport;
use Mata\Services\Status\StatusLevel;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ConfigValidationReportTest extends TestCase
{
    public function testMultipleSourcesMarkEveryFile(): void
    {
        $problem = new ConfigProblem(StatusLevel::Warning, 'config.database.missing', 'Incomplete', [
            ConfigSource::file('config.env', 'DB_HOST'),
            ConfigSource::file('mail.config.env', 'DB_PASSWORD'),
            ConfigSource::process('DB_USERNAME'),
        ]);
        $report = new ConfigValidationReport('/config', ['config.env' => true, 'mail.config.env' => true, 'db.config.env' => false], [$problem]);

        $this->assertSame(['config.env', 'mail.config.env'], $problem->files());
        $this->assertSame(StatusLevel::Warning, $report->fileStatus('config.env'));
        $this->assertSame(StatusLevel::Warning, $report->fileStatus('mail.config.env'));
        $this->assertNull($report->fileStatus('db.config.env'));
    }

    public function testProblemBelongsToItsOnlyFile(): void
    {
        $problem = new ConfigProblem(StatusLevel::Error, 'invalid', 'Invalid', [
            ConfigSource::file('config.env', 'DB_HOST'),
            ConfigSource::file('config.env', 'DB_PASSWORD'),
            ConfigSource::process('DB_USERNAME'),
        ]);
        $this->assertSame(['config.env'], $problem->files());

        $unattributed = new ConfigProblem(StatusLevel::Error, 'invalid', 'Unknown');
        $this->assertSame([], $unattributed->jsonSerialize()['files']);
        $this->assertSame(StatusLevel::Error, (new ConfigValidationReport('/config', [], [$unattributed]))->level());
    }
}
