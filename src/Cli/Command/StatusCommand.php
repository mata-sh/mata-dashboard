<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\EnvConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Validation\ConfigProblemCollector;
use Mata\Config\Validation\InstanceConfigValidator;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheType;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Cron\CronHeartbeatReader;
use Mata\Services\Status\FleetStatus;
use Mata\Services\Status\FleetStatusReader;
use Mata\Services\Status\StatusLevel;
use Mata\Utils\PathResolver;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'status', description: 'Fleet and cron status from the cron-warmed cache; never contacts nodes')]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly PathResolver $paths,
        private readonly string $configPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON')
            ->setHelp(<<<'HELP'
                Reads node health cached by cron/servers_data_fetch.php and the cron heartbeats. Nothing is
                fetched, recorded or cached, so monitoring systems can call it as often as they like.

                Exit codes (Nagios-style): 0 ok, 1 warning (maintenance, a cron job that never ran),
                2 critical (node unhealthy or unreachable, cron overdue), 3 unknown (no or stale cached
                health: servers_data_fetch is not running). The worst level wins; critical outranks unknown.
                Covers node health and cron only; metric thresholds and alarms are in the dashboard.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // LOG_PATH and TEMP_DIR move the heartbeat and cache directories.
            $this->paths->applyConfig((new EnvConfigLoader())->load(InstanceConfigValidator::ENV_FILES, $this->configPath));
        } catch (Throwable $exception) {
            $output->writeln("<error>Cannot load the env config: {$exception->getMessage()}</error>");

            return StatusLevel::Unknown->value;
        }

        $events = new ConfigProblemCollector();
        $servers = (new ServersConfigLoader($events, fallbackOnFailure: true))->load($this->configPath)->values;
        $ttl = new CacheTtlResolver((new TtlConfigLoader($events, fallbackOnFailure: true))->load($this->configPath));

        $status = (new FleetStatusReader(
            new FileCacheStore(new NullLogger(), $this->paths->tempPath . '/api-cache'),
            new ApiCacheKeyFactory(),
            new CronHeartbeatReader($this->paths->appPath . '/cron', $this->paths->logsPath . '/cron'),
            $ttl->get(CacheType::Health),
        ))->read($servers, time());
        $configProblems = count($events->take());

        if ($input->getOption('json')) {
            CliOutput::json($output, $status->toArray() + ['config_problems' => $configProblems]);
        } else {
            $this->writeHuman($output, $status, $configProblems);
        }

        return $status->level()->value;
    }

    private function writeHuman(OutputInterface $output, FleetStatus $status, int $configProblems): void
    {
        $tag = static fn (StatusLevel $level): string => match ($level) {
            StatusLevel::Ok => '<info>ok</info>',
            StatusLevel::Warning => '<comment>warning</comment>',
            StatusLevel::Critical => '<error>critical</error>',
            StatusLevel::Unknown => '<comment>unknown</comment>',
        };

        $output->writeln('Servers');
        foreach ($status->servers as $server) {
            $age = $server['age_seconds'] !== null ? " ({$server['age_seconds']}s ago)" : '';
            $output->writeln("  {$tag($server['level'])} {$server['name']}: {$server['health']}{$age}" . ($server['error'] !== null ? " — {$server['error']}" : ''));
        }
        if ($status->servers === []) {
            $output->writeln('  none configured');
        }
        $output->writeln('Cron');
        foreach ($status->cron as $job) {
            $output->writeln("  {$tag($job['level'])} {$job['job']}: {$job['state']}");
        }
        if ($configProblems > 0) {
            $output->writeln("<comment>{$configProblems} config problem(s); run bin/mata config validate</comment>");
        }
        $output->writeln('Overall: ' . $tag($status->level()));
    }
}
