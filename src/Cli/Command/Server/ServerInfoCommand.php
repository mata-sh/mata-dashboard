<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Server;

use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeDatasetCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Operating system, kernel, PHP version and extensions of a server. */
#[AsCommand(name: 'server:info', description: 'Show the OS, kernel, PHP version and extensions of a server')]
final class ServerInfoCommand extends AbstractNodeDatasetCommand
{
    protected function datasetName(InputInterface $input): string
    {
        return 'server info';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->getServerInfo(self::cacheType($live, CacheType::ServerInfo));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        return ['info' => is_array($result) ? $result : []];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        foreach (is_array($data['info']) ? $data['info'] : [] as $key => $value) {
            $output->writeln(sprintf('%-20s %s', CliOutput::value($key), CliOutput::value($value)));
        }
    }
}
