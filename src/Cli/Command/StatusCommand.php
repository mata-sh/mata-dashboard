<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheType;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Cron\CronHeartbeatReader;
use Mata\Services\Cron\Dto\CronJobState;
use Mata\Services\Status\Dto\FleetStatus;
use Mata\Services\Status\FleetStatusReader;
use Mata\Services\Status\StatusLevel;
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
        private readonly CliConfig $cli,
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

                Exit codes (Nagios-style): 0 ok, 1 warning (maintenance, a cron job that has not run a day
                after cron started; before that it is "pending" and ok, a config problem such as a dropped
                server), 2 error (Nagios CRITICAL: node unhealthy or unreachable, cron overdue), 3 unknown
                (no or stale cached health: servers_data_fetch is not running; servers.json rejected).
                The worst level wins; error outranks unknown.
                Covers node health and cron only; metric thresholds and alarms are in the dashboard.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // LOG_PATH and TEMP_DIR move the heartbeat and cache directories.
            $this->cli->load();
        } catch (Throwable $exception) {
            return CliOutput::fail($input, $output, CliOutput::withWebUserHint("Cannot load the config: {$exception->getMessage()}"), StatusLevel::Unknown);
        }

        $servers = (new ServersConfigLoader(fallbackOnFailure: true))->load($this->cli->configPath);
        $ttl = (new TtlConfigLoader(fallbackOnFailure: true))->load($this->cli->configPath);

        $status = (new FleetStatusReader(
            new FileCacheStore(new NullLogger(), $this->cli->paths->tempPath . '/api-cache'),
            new ApiCacheKeyFactory(),
            new CronHeartbeatReader($this->cli->paths->appPath . '/cron', $this->cli->paths->logsPath . '/cron'),
            (new CacheTtlResolver($ttl->section))->get(CacheType::Health),
        ))->read($servers->section->values, time(), [...$servers->problems, ...$ttl->problems]);

        if ($input->getOption('json')) {
            CliOutput::json($output, $status->toArray());
        } else {
            $this->writeHuman($output, $status);
        }

        return $status->level()->value;
    }

    private function writeHuman(OutputInterface $output, FleetStatus $status): void
    {
        $output->writeln('Servers');
        foreach ($status->servers as $server) {
            $age = $server['age_seconds'] !== null ? " ({$server['age_seconds']}s ago)" : '';
            $output->writeln('  ' . CliOutput::label($server['level']) . " {$server['name']}: {$server['health']}{$age}" . ($server['error'] !== null ? " — {$server['error']}" : ''));
        }
        if ($status->servers === []) {
            $output->writeln('  none configured');
        }
        $output->writeln('Cron');
        foreach ($status->cron as $job) {
            $output->writeln('  ' . CliOutput::label($job['level']) . " {$job['job']}: {$job['state']}");
        }
        $pending = count(array_filter($status->cron, static fn (array $job): bool => $job['state'] === CronJobState::Pending->value));
        if ($pending > 0) {
            $output->writeln("{$pending} job(s) have not run yet; cron started less than a day ago, so their first run may still be ahead");
        }
        $configProblems = count($status->configProblems);
        if ($configProblems > 0) {
            $output->writeln("<comment>{$configProblems} config problem(s); run bin/mata config validate</comment>");
        }
        $output->writeln('Overall: ' . CliOutput::label($status->level()));
    }
}
