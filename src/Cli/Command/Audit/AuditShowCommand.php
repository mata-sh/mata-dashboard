<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Audit;

use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `composer audit` result of an application: known advisories and abandoned packages.
 * cron/libraries_audit.php refreshes the cached result; --live runs the audit on the node,
 * which can take up to a minute.
 */
#[AsCommand(name: 'audit:show', description: 'Show the Composer security audit of an application')]
final class AuditShowCommand extends AbstractNodeEvidenceCommand
{
    protected function requiresApp(): bool
    {
        return true;
    }

    protected function dataset(InputInterface $input): string
    {
        return 'composer audit';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->fetchAppComposerAudit(self::app($input), self::cacheType($live, CacheType::Audit));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        return ['audit' => is_array($result) ? $result : []];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $audit = is_array($data['audit']) ? $data['audit'] : [];
        $counts = is_array($audit['counts'] ?? null) ? $audit['counts'] : [];
        $message = is_string($audit['message'] ?? null) ? ' — ' . CliOutput::value($audit['message']) : '';
        $output->writeln(sprintf(
            '%s: %s advisories (critical %s, high %s, medium %s, low %s)%s',
            CliOutput::value($audit['status'] ?? null),
            CliOutput::value($counts['total'] ?? null),
            CliOutput::value($counts['critical'] ?? null),
            CliOutput::value($counts['high'] ?? null),
            CliOutput::value($counts['medium'] ?? null),
            CliOutput::value($counts['low'] ?? null),
            $message,
        ));

        $advisories = is_array($audit['advisories'] ?? null) ? $audit['advisories'] : [];
        if ($advisories !== []) {
            // Compact: titles can be long, and borders would stretch every row to them.
            $table = (new Table($output))->setStyle('compact');
            $table->setHeaders(['Severity', 'Package', 'Advisory', 'Title']);
            foreach ($advisories as $advisory) {
                $table->addRow([
                    CliOutput::value($advisory['severity'] ?? null),
                    CliOutput::value($advisory['package'] ?? null),
                    CliOutput::value($advisory['cve'] ?? $advisory['advisory_id'] ?? null),
                    CliOutput::value($advisory['title'] ?? null),
                ]);
            }
            $table->render();
        }

        // The node lists an abandoned package as its name or as {package, replacement}.
        $abandoned = [];
        foreach (is_array($audit['abandoned'] ?? null) ? $audit['abandoned'] : [] as $package) {
            $abandoned[] = is_array($package) ? ($package['package'] ?? '') : $package;
        }
        if ($abandoned !== []) {
            $output->writeln('abandoned: ' . CliOutput::value($abandoned));
        }
    }
}
