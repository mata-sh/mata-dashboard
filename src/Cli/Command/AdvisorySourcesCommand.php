<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Services\Advisory\Report\AdvisoryReport;
use Mata\Services\Advisory\Report\AdvisoryReportBuilder;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** The advisory sources of advisories.json with their poll state. */
#[AsCommand(name: 'advisory:sources', description: 'List the security advisory sources and their poll state')]
final class AdvisorySourcesCommand extends AbstractAdvisoryCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setHelp('Exit codes: 0 every enabled source polled successfully, 1 a source was not polled yet, 2 a source is failing.');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $states = $this->advisories->states->all();
        $totals = $this->advisories->advisories->sourceTotals();

        $sources = [];
        $levels = [StatusLevel::Ok];
        foreach ($this->advisories->config->sources as $key => $source) {
            $state = $states[$key] ?? null;
            $health = AdvisoryReportBuilder::health($source, $state);
            $levels[] = match ($health) {
                'failing' => StatusLevel::Error,
                'never_polled' => StatusLevel::Warning,
                default => StatusLevel::Ok,
            };
            $sources[] = [
                'key' => $key,
                'name' => $source->name,
                'description' => $source->description,
                'type' => $source->type->value,
                'url' => $source->url,
                'enabled' => $source->enabled,
                'notify' => $source->notify,
                'poll_interval_minutes' => $source->pollIntervalMinutes,
                'filters' => ['keywords' => $source->keywords, 'min_severity' => $source->minSeverity->value],
                'health' => $health,
                'last_polled_at' => AdvisoryReport::isoTime($state?->lastPolledAt),
                'last_success_at' => AdvisoryReport::isoTime($state?->lastSuccessAt),
                'consecutive_failures' => $state->consecutiveFailures ?? 0,
                'last_error' => $state?->lastError,
                'advisories' => $totals[$key] ?? ['total' => 0, 'relevant' => 0, 'unreviewed' => 0, 'latest' => null],
            ];
        }
        $level = StatusLevel::worst($levels);

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => $level->label(), 'sources' => $sources]);

            return $level->value;
        }

        if ($sources === []) {
            $output->writeln('advisories.json defines no valid sources; see bin/mata config validate advisories.json');
        }
        foreach ($sources as $source) {
            $mark = CliOutput::mark(match ($source['health']) {
                'failing' => StatusLevel::Error,
                'never_polled' => StatusLevel::Warning,
                default => StatusLevel::Ok,
            });
            $output->writeln(sprintf(
                '%s %-16s %-7s %-12s notify=%-3s every %dm  advisories=%d relevant=%d unreviewed=%d  last success: %s',
                $mark,
                $source['key'],
                $source['type'],
                str_replace('_', ' ', $source['health']),
                $source['notify'] ? 'yes' : 'no',
                $source['poll_interval_minutes'],
                $source['advisories']['total'],
                $source['advisories']['relevant'],
                $source['advisories']['unreviewed'],
                $source['last_success_at'] ?? 'never',
            ));
            if ($source['last_error'] !== null) {
                $output->writeln('    <error>' . OutputFormatter::escape($source['last_error']) . '</error>');
            }
        }

        return $level->value;
    }
}
