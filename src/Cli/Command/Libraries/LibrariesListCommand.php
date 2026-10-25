<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Libraries;

use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Installed Composer packages of each application, from composer.lock on the node. */
#[AsCommand(name: 'libraries:list', description: 'List the Composer packages of the applications of a server')]
final class LibrariesListCommand extends AbstractNodeEvidenceCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Only this application')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Only packages whose name contains this, e.g. symfony/');
    }

    protected function dataset(InputInterface $input): string
    {
        return 'libraries';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->getAppsLibraries(self::cacheType($live, CacheType::Libraries));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $onlyApp = $input->getOption('app');
        $package = $input->getOption('package');
        $apps = [];
        foreach (is_array($result) ? $result : [] as $app => $libraries) {
            if (is_string($onlyApp) && $app !== $onlyApp) {
                continue;
            }
            $libraries = array_values(array_filter(
                is_array($libraries) ? $libraries : [],
                static fn (mixed $library): bool => is_array($library)
                    && (!is_string($package) || str_contains((string) ($library['name'] ?? ''), $package)),
            ));
            $apps[] = ['app' => (string) $app, 'packages' => array_map(
                static fn (array $library): array => ['name' => $library['name'] ?? null, 'version' => $library['version'] ?? null],
                $libraries,
            )];
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
            $name = OutputFormatter::escape($app['app']);
            foreach ($app['packages'] as $library) {
                $table->addRow([
                    $name,
                    OutputFormatter::escape((string) $library['name']),
                    OutputFormatter::escape((string) $library['version']),
                ]);
                $name = '';
            }
        }
        $table->render();
    }
}
