<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Apps;

use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Session counts of an application: last hour, last day and total, from its session store. */
#[AsCommand(name: 'apps:sessions', description: 'Show the session counts of an application')]
final class AppsSessionsCommand extends AbstractNodeEvidenceCommand
{
    protected function requiresApp(): bool
    {
        return true;
    }

    protected function dataset(InputInterface $input): string
    {
        return 'sessions';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->fetchAppSessions(self::app($input), self::cacheType($live, CacheType::Sessions));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        return ['sessions' => is_array($result) ? $result : []];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $sessions = is_array($data['sessions']) ? $data['sessions'] : [];
        $error = is_string($sessions['error'] ?? null) ? ' — ' . CliOutput::value($sessions['error']) : '';
        $output->writeln(sprintf(
            '%s (%s): last hour %s, last day %s, total %s%s',
            CliOutput::value($sessions['status'] ?? null),
            CliOutput::value($sessions['type'] ?? null),
            CliOutput::value($sessions['count_last_hour'] ?? null),
            CliOutput::value($sessions['count_last_day'] ?? null),
            CliOutput::value($sessions['count_total'] ?? null),
            $error,
        ));
    }
}
