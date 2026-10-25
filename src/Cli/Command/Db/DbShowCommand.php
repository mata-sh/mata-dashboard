<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Db;

use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Status of each database connection a node monitors, and the databases on it. */
#[AsCommand(name: 'db:show', description: 'Show the monitored database connections of a server')]
final class DbShowCommand extends AbstractNodeEvidenceCommand
{
    protected function dataset(InputInterface $input): string
    {
        return 'databases';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->getDatabaseOverview(self::cacheType($live, CacheType::Databases));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $connections = is_array($result) && is_array($result['connections'] ?? null) ? $result['connections'] : [];

        return ['connections' => array_values($connections)];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $connections = is_array($data['connections']) ? $data['connections'] : [];
        if ($connections === []) {
            $output->writeln('No monitored database connections.');
        }
        foreach ($connections as $connection) {
            $name = OutputFormatter::escape((string) ($connection['connection'] ?? '-'));
            $engine = OutputFormatter::escape((string) ($connection['engine'] ?? '-'));
            $error = $connection['error'] ?? null;
            if ($error !== null) {
                $message = is_array($error) ? ($error['message'] ?? 'error') : $error;
                $output->writeln("<error>{$name}</error> ({$engine}): " . OutputFormatter::escape((string) $message));

                continue;
            }
            $status = is_array($connection['status'] ?? null) ? $connection['status'] : [];
            $output->writeln(sprintf(
                '<info>%s</info> (%s) connections %s/%s, running %s',
                $name,
                $engine,
                $status['connections'] ?? '-',
                $status['max_connections'] ?? '-',
                $status['running'] ?? '-',
            ));
            $databases = is_array($connection['databases'] ?? null) ? $connection['databases'] : [];
            $names = array_map(static fn (mixed $database): string => is_array($database) ? (string) ($database['name'] ?? '') : '', $databases);
            $output->writeln('    databases: ' . ($names === [] ? '-' : OutputFormatter::escape(implode(', ', $names))));
        }
    }
}
