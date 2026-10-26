<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Db;

use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeDatasetCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Status of each database connection a node monitors, and the databases on it. */
#[AsCommand(name: 'db:show', description: 'Show the monitored database connections of a server')]
final class DbShowCommand extends AbstractNodeDatasetCommand
{
    protected function datasetName(InputInterface $input): string
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

            return;
        }

        foreach ($connections as $connection) {
            $name = CliOutput::value($connection['connection'] ?? null);
            $engine = CliOutput::value($connection['engine'] ?? null);
            $error = $connection['error'] ?? null;
            if ($error !== null) {
                $message = is_array($error) ? ($error['message'] ?? 'error') : $error;
                $output->writeln("<error>{$name}</error> ({$engine}): " . CliOutput::value($message));

                continue;
            }
            $status = is_array($connection['status'] ?? null) ? $connection['status'] : [];
            $output->writeln(sprintf(
                '<info>%s</info> (%s) connections %s/%s, running %s',
                $name,
                $engine,
                CliOutput::value($status['connections'] ?? null),
                CliOutput::value($status['max_connections'] ?? null),
                CliOutput::value($status['running'] ?? null),
            ));
            $output->writeln('    databases: ' . CliOutput::value(self::databaseNames($connection['databases'] ?? null)));
        }
    }

    /** @return list<string> */
    private static function databaseNames(mixed $databases): array
    {
        $names = [];
        foreach (is_array($databases) ? $databases : [] as $database) {
            if (is_array($database) && is_string($database['name'] ?? null)) {
                $names[] = $database['name'];
            }
        }

        return $names;
    }
}
