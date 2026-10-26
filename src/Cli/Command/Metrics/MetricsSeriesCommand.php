<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Metrics;

use DateTimeImmutable;
use InvalidArgumentException;
use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractResultCommand;
use Mata\Cli\Provenance;
use Mata\Cli\StoredMetrics;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Services\ServerMetrics\MetricsRequestValidator;
use Mata\Services\Status\StatusLevel;
use Mata\Services\View\MetricsViewBuilder;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One stored metric of a server over time, as the dashboard charts it. Hourly and daily points
 * are averages; raw points are single samples. Times are UTC. Demo mode generates the series.
 */
#[AsCommand(name: 'metrics:series', description: 'Show the stored history of one server metric')]
final class MetricsSeriesCommand extends AbstractResultCommand
{
    protected function configure(): void
    {
        $catalog = new MetricCatalog();
        parent::configure();
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server name from servers.json')
            ->addArgument('metric', InputArgument::REQUIRED, 'One of: ' . implode(', ', $catalog->metrics()))
            ->addOption('range', null, InputOption::VALUE_REQUIRED, 'How far back: ' . CliInput::DURATIONS . ' or all', '24h')
            ->addOption('granularity', null, InputOption::VALUE_REQUIRED, 'One of: ' . implode(', ', $catalog->granularities()), 'auto')
            ->setHelp('auto picks raw samples up to 6h, hourly averages up to 7d and daily averages beyond.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->cli->load();
        $server = (string) $input->getArgument('server');
        $metric = (string) $input->getArgument('metric');
        $range = strtolower((string) $input->getOption('range'));
        $requestedGranularity = strtolower((string) $input->getOption('granularity'));
        $policy = new MetricQueryPolicy();
        $servers = (new ServersConfigLoader(fallbackOnFailure: true))->load($this->cli->configPath)->section->values;

        $validator = new MetricsRequestValidator(new MetricCatalog(), $policy);
        $validation = $validator->validate($server, $metric, $range, $requestedGranularity, $servers);
        if ($validation->hasErrors()) {
            throw new InvalidArgumentException(implode('; ', $validation->errors) . '.');
        }
        $metric = $validation->normalizedMetric;
        $granularity = $policy->resolveGranularity($range, $requestedGranularity);

        $demo = $config->environment->env === 'demo';
        $query = StoredMetrics::query($config, $servers);
        $series = (new MetricsViewBuilder($query, new NullLogger()))->buildServerMetricTimeSeries($server, $metric, $range, $granularity);

        $points = [];
        foreach ($series['labels'] as $index => $label) {
            $points[] = ['time' => $label, 'value' => $series['data'][$index] ?? null];
        }
        $data = Provenance::fromDatabase("server_metrics_{$granularity}", new DateTimeImmutable(), $demo) + [
            'server' => $server,
            'metric' => $metric,
            'range' => $range,
            'granularity' => $granularity,
            'timezone' => 'UTC',
            'unit' => $series['unit'],
            'min' => $series['min'],
            'max' => $series['max'],
            'avg' => $series['avg'],
            'points' => $points,
        ];

        return $this->report($input, $output, StatusLevel::Ok, $data, fn () => $this->writeHuman($output, $data));
    }

    /** @param array<string,mixed> $data */
    private function writeHuman(OutputInterface $output, array $data): void
    {
        $points = is_array($data['points']) ? $data['points'] : [];
        if ($points === []) {
            $output->writeln(sprintf(
                "No %s %s data for '%s' in the last %s. Check bin/mata metrics status.",
                $data['granularity'],
                $data['metric'],
                CliOutput::value($data['server']),
                $data['range'],
            ));

            return;
        }
        foreach ($points as $point) {
            $output->writeln(sprintf('%-19s %s', $point['time'], CliOutput::value($point['value'])));
        }
        $output->writeln(sprintf(
            '<comment>%s %s (%s, UTC, %s): min %s, max %s, avg %s</comment>',
            CliOutput::value($data['server']),
            $data['metric'],
            CliOutput::value($data['unit']),
            $data['granularity'],
            CliOutput::value($data['min']),
            CliOutput::value($data['max']),
            CliOutput::value($data['avg']),
        ));
    }
}
