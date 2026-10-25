<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Apps;

use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Session counts of an application: last hour, last day and total, from its session store. */
#[AsCommand(name: 'apps:sessions', description: 'Show the session counts of an application')]
final class AppsSessionsCommand extends AbstractNodeEvidenceCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('app', InputArgument::REQUIRED, 'Application name, see apps list');
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
        return ['app' => (string) $input->getArgument('app'), 'sessions' => is_array($result) ? $result : []];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $sessions = is_array($data['sessions']) ? $data['sessions'] : [];
        $output->writeln(sprintf(
            '%s (%s): last hour %s, last day %s, total %s%s',
            $sessions['status'] ?? '-',
            $sessions['type'] ?? '-',
            $sessions['count_last_hour'] ?? '-',
            $sessions['count_last_day'] ?? '-',
            $sessions['count_total'] ?? '-',
            is_string($sessions['error'] ?? null) ? ' — ' . OutputFormatter::escape($sessions['error']) : '',
        ));
    }
}
