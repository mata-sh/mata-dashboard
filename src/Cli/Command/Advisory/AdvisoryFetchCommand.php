<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Advisory;

use DateTimeImmutable;
use Mata\Cli\CliOutput;
use Mata\Services\Advisory\Dto\AdvisoryPollResult;
use Mata\Services\Advisory\Dto\AdvisoryPollStatus;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Polls advisory sources now, exactly like cron/advisories_poll.php: new relevant advisories
 * are stored and notified. --preview only fetches and filters, to try a source or its filters.
 */
#[AsCommand(name: 'advisory:fetch', description: 'Poll security advisory sources now, or preview what a source returns')]
final class AdvisoryFetchCommand extends AbstractAdvisoryCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Only this source (key in advisories.json); default: every enabled source')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Poll even when the poll interval has not passed')
            ->addOption('preview', null, InputOption::VALUE_NONE, 'Fetch and filter without storing or notifying')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Advisories listed per source with --preview', '20')
            ->setHelp(<<<'HELP'
                The first successful poll of a source stores what it already lists without notifying (baseline).
                After that every new relevant advisory is notified once, as configured by the source's "notify".
                --preview works for disabled sources too and never writes.

                Exit codes: 0 polled or not due, 2 a source failed.
                HELP);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getArgument('source');
        $preview = (bool) $input->getOption('preview');
        if (is_string($key)) {
            $source = $this->advisories->config->source($key);
            if ($source === null) {
                return CliOutput::fail($input, $output, "Unknown advisory source '{$key}'. Sources: " . implode(', ', array_keys($this->advisories->config->sources)));
            }
            if (!$source->enabled && !$preview) {
                return CliOutput::fail($input, $output, "Advisory source '{$key}' is disabled; enable it in advisories.json or use --preview.");
            }
            $sources = [$source];
        } else {
            $sources = array_values($this->advisories->config->enabledSources());
        }

        $log = $this->logger('advisory-fetch');
        $poller = $this->advisories->poller($this->eventManager($log), $log);
        $results = [];
        foreach ($sources as $source) {
            $results[] = $preview ? $poller->preview($source) : $poller->poll($source, new DateTimeImmutable(), (bool) $input->getOption('force'));
        }
        $failed = array_filter($results, static fn (AdvisoryPollResult $result): bool => $result->status === AdvisoryPollStatus::Failed);
        $level = $failed === [] ? StatusLevel::Ok : StatusLevel::Error;
        $limit = max(0, (int) $input->getOption('limit'));

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => $level->label(), 'results' => array_map(
                fn (AdvisoryPollResult $result): array => $result->toArray() + ($preview ? ['advisories' => $this->previewRows($result, $limit)] : []),
                $results,
            )]);

            return $level->value;
        }

        if ($results === []) {
            $output->writeln('No enabled advisory sources.');
        }
        foreach ($results as $result) {
            $mark = CliOutput::mark($result->status === AdvisoryPollStatus::Failed ? StatusLevel::Error : StatusLevel::Ok);
            $output->writeln("{$mark} {$result->sourceKey}: " . OutputFormatter::escape($result->summaryLine()));
            foreach ($this->previewRows($result, $limit) as $row) {
                $output->writeln(sprintf(
                    '    %s %-8s %s %s%s',
                    $row['relevant'] ? '<info>+</info>' : '<comment>-</comment>',
                    $row['severity'],
                    $row['published_at'] ?? '----------',
                    OutputFormatter::escape(mb_strimwidth($row['title'], 0, 90, '…')),
                    $row['matched_keywords'] === [] ? '' : ' <comment>[' . OutputFormatter::escape(implode(', ', $row['matched_keywords'])) . ']</comment>',
                ));
            }
        }
        if ($preview) {
            $output->writeln('Preview only: nothing was stored. + relevant, - filtered out by keywords or min_severity.');
        }

        return $level->value;
    }

    /** @return list<array{title:string,severity:string,published_at:?string,relevant:bool,matched_keywords:list<string>,link:?string,cve_ids:list<string>}> */
    private function previewRows(AdvisoryPollResult $result, int $limit): array
    {
        // Relevant first: they are what a filter change is checked for.
        $entries = $result->preview;
        usort($entries, static fn (array $a, array $b): int => $b['relevance']->relevant <=> $a['relevance']->relevant);
        $rows = [];
        foreach (array_slice($entries, 0, $limit) as $entry) {
            $item = $entry['item'];
            $rows[] = [
                'title' => $item->title,
                'severity' => $item->severity->value,
                'published_at' => $item->publishedAt?->format('Y-m-d'),
                'relevant' => $entry['relevance']->relevant,
                'matched_keywords' => $entry['relevance']->matchedKeywords,
                'link' => $item->link,
                'cve_ids' => $item->cveIds,
            ];
        }

        return $rows;
    }
}
