<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Apps;

use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apps:list', description: 'List the applications of one or all servers with their log status')]
final class AppsListCommand extends AbstractNodeEvidenceCommand
{
    protected function serverOptional(): bool
    {
        return true;
    }

    protected function dataset(InputInterface $input): string
    {
        return 'apps';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->getApps(self::cacheType($live, CacheType::Apps));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $apps = [];
        foreach (is_array($result) ? $result : [] as $name => $app) {
            $apps[] = ['name' => (string) $name] + (is_array($app) ? $app : []);
        }

        return ['apps' => $apps];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $apps = is_array($data['apps']) ? $data['apps'] : [];
        if ($apps === []) {
            $output->writeln('No applications.');

            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Name', 'Env', 'Log', 'URL']);
        foreach ($apps as $app) {
            $table->addRow([
                CliOutput::value($app['name']),
                CliOutput::value($app['env'] ?? null),
                self::logStatus($app['status'] ?? null),
                CliOutput::value($app['url'] ?? null),
            ]);
        }
        $table->render();
    }

    /** The node's log analysis counts, or why there are none. */
    private static function logStatus(mixed $status): string
    {
        if (!is_array($status)) {
            return 'log analysis off';
        }

        return sprintf('fatal=%d warnings=%d', $status['fatal'] ?? 0, $status['warnings'] ?? 0);
    }
}
