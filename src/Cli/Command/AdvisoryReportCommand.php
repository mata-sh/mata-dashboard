<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Mata\Cli\CliOutput;
use Mata\Services\Advisory\Report\AdvisoryReport;
use Mata\Services\Advisory\Report\AdvisoryReportPresenter;
use Mata\Services\Advisory\Report\AdvisoryReportQuery;
use Mata\Services\Advisory\Report\AdvisoryReportRenderer;
use Mata\Services\Audit\Severity;
use Mata\Services\Status\StatusLevel;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Summarizes stored advisories over a period as PDF, Markdown, HTML or JSON, the same
 * report the Advisories page downloads and the digest mails.
 */
#[AsCommand(name: 'advisory:report', description: 'Summarize security advisories as PDF, Markdown, HTML or JSON')]
final class AdvisoryReportCommand extends AbstractAdvisoryCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Period ending now, in days', '7')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Period start (YYYY-MM-DD, UTC); overrides --days')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Period end, exclusive (YYYY-MM-DD, UTC); default now')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these sources (repeatable)')
            ->addOption('min-severity', null, InputOption::VALUE_REQUIRED, 'low, medium, high or critical', 'low')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Include advisories filtered out by the source filters')
            ->addOption('include-dismissed', null, InputOption::VALUE_NONE, 'Include advisories reviewed as not relevant')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Comma-separated: ' . implode(', ', AdvisoryReportRenderer::FORMATS), 'md')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write; a directory for several formats')
            ->addOption('mail', null, InputOption::VALUE_NONE, 'Mail the report like the digest (HTML body, Markdown text, PDF attached)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Recipients for --mail; default digest recipients or MAIL_ALARM_RECIPIENTS')
            ->setHelp(<<<'HELP'
                Without --output a single md, html or json report is printed to standard output; PDF needs --output.
                Examples:
                  bin/mata advisory:report --days 30 --format pdf,md -o reports/
                  bin/mata advisory:report --source cert-bund --min-severity high --format json
                  bin/mata advisory:report --mail --to security@example.com

                Exit codes: 0 written or sent, 2 invalid options or delivery failed.
                HELP);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        try {
            $query = $this->query($input);
            $formats = $this->formats($input);
        } catch (InvalidArgumentException $exception) {
            return CliOutput::fail($input, $output, $exception->getMessage());
        }

        $report = $this->advisories->reportBuilder()->build($query, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $renderer = $this->advisories->reportRenderer();
        $target = $input->getOption('output');
        $json = (bool) $input->getOption('json');
        $mail = (bool) $input->getOption('mail');

        $files = [];
        if (is_string($target) && $target !== '') {
            try {
                $files = $this->write($report, $renderer, $formats, $target);
            } catch (RuntimeException $exception) {
                return CliOutput::fail($input, $output, $exception->getMessage());
            }
        } elseif (!$mail) {
            if (count($formats) !== 1 || $formats[0] === 'pdf') {
                return CliOutput::fail($input, $output, 'Pass --output for PDF or several formats.');
            }
            if (!$json) {
                $output->write($renderer->render($report, $formats[0]), false, OutputInterface::OUTPUT_RAW);

                return StatusLevel::Ok->value;
            }
        }

        $recipients = [];
        if ($mail) {
            try {
                $to = array_values(array_filter((array) $input->getOption('to'), 'is_string'));
                $recipients = $this->advisories->digestMailer()->send($report, $to);
            } catch (RuntimeException $exception) {
                return CliOutput::fail($input, $output, $exception->getMessage());
            }
        }

        $summary = sprintf(
            '%d advisories (%d critical or high), %s – %s',
            $report->total,
            $report->criticalOrHighCount(),
            AdvisoryReportPresenter::day($query->since),
            AdvisoryReportPresenter::day($query->until)
        );
        $payload = ['totals' => $report->toArray()['totals'], 'files' => $files, 'mailed_to' => $recipients];
        if ($files === [] && !$mail) {
            $payload['report'] = $report->toArray();
        }

        return CliOutput::ok($input, $output, $summary . ($files !== [] ? "\nWrote " . implode(', ', $files) : '')
            . ($recipients !== [] ? "\nMailed to " . implode(', ', $recipients) : ''), $payload);
    }

    private function query(InputInterface $input): AdvisoryReportQuery
    {
        $utc = new DateTimeZone('UTC');
        $until = $this->date($input->getOption('until'), '--until') ?? new DateTimeImmutable('now', $utc);
        $since = $this->date($input->getOption('since'), '--since');
        if ($since === null) {
            $days = $input->getOption('days');
            if (!is_string($days) || !ctype_digit($days) || (int) $days < 1 || (int) $days > 3660) {
                throw new InvalidArgumentException('--days must be a whole number from 1 to 3660.');
            }
            $since = $until->modify('-' . (int) $days . ' days');
        }
        if ($since >= $until) {
            throw new InvalidArgumentException('--since must be before --until.');
        }

        $minSeverity = Severity::tryFrom((string) $input->getOption('min-severity'));
        if ($minSeverity === null || $minSeverity === Severity::Unknown) {
            throw new InvalidArgumentException('--min-severity must be low, medium, high or critical.');
        }

        return new AdvisoryReportQuery(
            since: $since,
            until: $until,
            sourceKeys: $this->sourceOption($input),
            relevantOnly: !$input->getOption('all'),
            minSeverity: $minSeverity,
            includeNotRelevant: (bool) $input->getOption('include-dismissed'),
        );
    }

    private function date(mixed $value, string $option): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$option} must be a date like 2026-10-01.");
        }

        return $date;
    }

    /** @return non-empty-list<string> */
    private function formats(InputInterface $input): array
    {
        $formats = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower((string) $input->getOption('format')))))));
        $unknown = array_diff($formats, AdvisoryReportRenderer::FORMATS);
        if ($formats === [] || $unknown !== []) {
            throw new InvalidArgumentException('--format must list ' . implode(', ', AdvisoryReportRenderer::FORMATS) . '.');
        }

        return $formats;
    }

    /**
     * A target ending in "/", an existing directory, or several formats name a directory.
     *
     * @param non-empty-list<string> $formats
     *
     * @return list<string> written paths
     */
    private function write(AdvisoryReport $report, AdvisoryReportRenderer $renderer, array $formats, string $target): array
    {
        $isDirectory = str_ends_with($target, '/') || is_dir($target) || count($formats) > 1;
        $directory = $isDirectory ? rtrim($target, '/') : dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create directory {$directory}.");
        }

        $written = [];
        foreach ($formats as $format) {
            $path = $isDirectory ? $directory . '/' . AdvisoryReportRenderer::fileName($report, $format) : $target;
            if (file_put_contents($path, $renderer->render($report, $format)) === false) {
                throw new RuntimeException("Cannot write {$path}.");
            }
            $written[] = $path;
        }

        return $written;
    }
}
