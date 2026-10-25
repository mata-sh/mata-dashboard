<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Audit;

use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
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
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('app', InputArgument::REQUIRED, 'Application name, see apps list');
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
        return ['app' => (string) $input->getArgument('app'), 'audit' => is_array($result) ? $result : []];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $audit = is_array($data['audit']) ? $data['audit'] : [];
        $counts = is_array($audit['counts'] ?? null) ? $audit['counts'] : [];
        $output->writeln(sprintf(
            '%s: %s advisories (critical %s, high %s, medium %s, low %s)%s',
            $audit['status'] ?? '-',
            $counts['total'] ?? 0,
            $counts['critical'] ?? 0,
            $counts['high'] ?? 0,
            $counts['medium'] ?? 0,
            $counts['low'] ?? 0,
            is_string($audit['message'] ?? null) ? ' — ' . OutputFormatter::escape($audit['message']) : '',
        ));
        $advisories = is_array($audit['advisories'] ?? null) ? $audit['advisories'] : [];
        if ($advisories !== []) {
            // Compact: titles can be long, and borders would stretch every row to them.
            $table = (new Table($output))->setStyle('compact');
            $table->setHeaders(['SEVERITY', 'PACKAGE', 'ADVISORY', 'TITLE']);
            foreach ($advisories as $advisory) {
                $table->addRow([
                    $advisory['severity'] ?? '-',
                    OutputFormatter::escape((string) ($advisory['package'] ?? '-')),
                    OutputFormatter::escape((string) ($advisory['cve'] ?? $advisory['advisory_id'] ?? '')),
                    OutputFormatter::escape((string) ($advisory['title'] ?? '')),
                ]);
            }
            $table->render();
        }
        $abandoned = is_array($audit['abandoned'] ?? null) ? $audit['abandoned'] : [];
        if ($abandoned !== []) {
            $output->writeln('abandoned: ' . OutputFormatter::escape(implode(', ', array_map(
                static fn (mixed $package): string => is_array($package) ? (string) ($package['package'] ?? '') : (string) $package,
                $abandoned,
            ))));
        }
    }
}
