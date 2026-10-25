<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Cronjobs;

use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The heartbeat files of the cronjobs a node watches: whether each exists, when it last
 * changed and its last lines. These are the monitored server's jobs, not the dashboard's own;
 * those are in `status`.
 */
#[AsCommand(name: 'cronjobs:show', description: "Show the heartbeats of a server's monitored cronjobs")]
final class CronjobsShowCommand extends AbstractNodeEvidenceCommand
{
    protected function dataset(InputInterface $input): string
    {
        return 'cronjobs';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->fetchServerCronjobs(self::cacheType($live, CacheType::Cronjobs));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        return ['cronjobs' => array_values(array_filter(is_array($result) ? $result : [], 'is_array'))];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $cronjobs = is_array($data['cronjobs']) ? $data['cronjobs'] : [];
        if ($cronjobs === []) {
            $output->writeln('No monitored cronjobs.');
        }
        foreach ($cronjobs as $cronjob) {
            $state = match (true) {
                ($cronjob['exists'] ?? false) !== true => '<error>missing</error>',
                ($cronjob['stale'] ?? false) === true => '<comment>stale</comment>',
                default => '<info>ok</info>',
            };
            $output->writeln(sprintf('%s %s (last change %s)', $state, OutputFormatter::escape((string) ($cronjob['path'] ?? '-')), $cronjob['mtime'] ?? '-'));
            foreach (is_array($cronjob['content_tail'] ?? null) ? $cronjob['content_tail'] : [] as $line) {
                $output->writeln('    ' . OutputFormatter::escape((string) $line));
            }
        }
    }
}
