<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Libraries;

use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeDatasetCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Installed Composer packages of each application, from composer.lock on the node. */
#[AsCommand(name: 'libraries:list', description: 'List the Composer packages of the applications of a server')]
final class LibrariesListCommand extends AbstractNodeDatasetCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Only this application')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Only packages whose name contains this, e.g. symfony/');
    }

    protected function datasetName(InputInterface $input): string
    {
        return 'libraries';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->getAppsLibraries(self::cacheType($live, CacheType::Libraries));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $onlyApp = CliInput::stringOption($input, 'app');
        $search = CliInput::stringOption($input, 'package');

        $apps = [];
        foreach (is_array($result) ? $result : [] as $app => $libraries) {
            $app = (string) $app;
            if ($onlyApp !== null && $app !== $onlyApp) {
                continue;
            }
            $packages = [];
            foreach (is_array($libraries) ? $libraries : [] as $library) {
                $name = is_array($library) ? ($library['name'] ?? null) : null;
                if (!is_string($name) || ($search !== null && !str_contains($name, $search))) {
                    continue;
                }
                $packages[] = ['name' => $name, 'version' => $library['version'] ?? null];
            }
            $apps[] = ['app' => $app, 'packages' => $packages];
        }

        return ['apps' => $apps];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $apps = is_array($data['apps']) ? $data['apps'] : [];
        if ($apps === []) {
            $output->writeln('No applications with Composer packages.');

            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Application', 'Package', 'Version']);
        foreach ($apps as $index => $app) {
            if ($index > 0) {
                $table->addRow(new TableSeparator());
            }
            // The application name only on its first row, so each group reads as one block.
            $name = CliOutput::value($app['app']);
            foreach ($app['packages'] as $library) {
                $table->addRow([$name, CliOutput::value($library['name']), CliOutput::value($library['version'])]);
                $name = '';
            }
        }
        $table->render();
    }
}
