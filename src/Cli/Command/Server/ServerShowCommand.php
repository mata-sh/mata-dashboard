<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Server;

use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The node's current system snapshot: CPU, RAM, disk, load, uptime, process and cronjob
 * counts. Cron caches it every run, so the cached copy is rarely older than a few minutes.
 * RAM and disk sizes are in kilobytes.
 */
#[AsCommand(name: 'server:show', description: 'Show CPU, RAM, disk, load and process counts of one or all servers')]
final class ServerShowCommand extends AbstractNodeEvidenceCommand
{
    protected function serverOptional(): bool
    {
        return true;
    }

    protected function dataset(InputInterface $input): string
    {
        return 'system';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->getServerSystemData(self::cacheType($live, CacheType::ServerData));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $system = is_array($result) ? $result : [];

        return ['system' => $system + [
            'ram_percent' => self::percent($system['ram_used'] ?? null, $system['ram_capacity'] ?? null),
            'disk_percent' => self::percent($system['disk_space_used'] ?? null, $system['disk_space_capacity'] ?? null),
        ]];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $system = is_array($data['system']) ? $data['system'] : [];
        $processes = is_array($system['processes'] ?? null) ? $system['processes'] : [];
        $cronjobs = is_array($system['cronjobs'] ?? null) ? $system['cronjobs'] : [];
        $output->writeln(sprintf(
            'cpu %s%% · ram %s%% of %s · disk %s%% of %s · load %s · up %s',
            CliOutput::value($system['cpu_usage'] ?? null),
            CliOutput::value($system['ram_percent'] ?? null),
            self::size($system['ram_capacity'] ?? null),
            CliOutput::value($system['disk_percent'] ?? null),
            self::size($system['disk_space_capacity'] ?? null),
            CliOutput::value($system['load_average'] ?? null),
            is_numeric($system['uptime'] ?? null) ? intdiv((int) $system['uptime'], 86400) . 'd' : '-',
        ));
        $output->writeln(sprintf(
            'processes %s (php %s, sql %s) · files %s · cronjobs %s working, %s stale, %s missing',
            CliOutput::value($processes['total'] ?? null),
            CliOutput::value($processes['php'] ?? null),
            CliOutput::value($processes['sql'] ?? null),
            CliOutput::value($system['total_system_filecount'] ?? null),
            CliOutput::value($cronjobs['working'] ?? null),
            CliOutput::value($cronjobs['stale'] ?? null),
            CliOutput::value($cronjobs['missing'] ?? null),
        ));
    }

    private static function percent(mixed $used, mixed $capacity): ?float
    {
        return is_numeric($used) && is_numeric($capacity) && $capacity > 0 ? round($used / $capacity * 100, 1) : null;
    }

    private static function size(mixed $kilobytes): string
    {
        return is_numeric($kilobytes) ? round($kilobytes / 1048576, 1) . ' GiB' : '-';
    }
}
