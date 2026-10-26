<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use DateTimeImmutable;
use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\NodeDatasetReader;
use Mata\Cli\Provenance;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only node data for one server, as the dashboard shows it. Reads the API cache that cron
 * and the dashboard keep warm and never contacts the node, unless --live asks the node directly.
 * Demo mode generates the data, as the dashboard does.
 *
 * Exits ok with data, warning when the data is a copy past its TTL, error without data. Fleet-wide
 * commands (coversFleet) report each server and exit with the worst level.
 */
abstract class AbstractNodeDatasetCommand extends AbstractResultCommand
{
    /** Names the data in errors and in the JSON `source`, e.g. 'logs'. */
    abstract protected function datasetName(InputInterface $input): string;

    /**
     * Pass self::cacheType($live, …) to the collector, so --live bypasses the cache.
     *
     * @return null|array<string,mixed> the collector's response, null when there is none
     */
    abstract protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array;

    /**
     * The command's JSON fields from the response `result`.
     *
     * @return array<string,mixed>
     */
    abstract protected function extract(mixed $result, InputInterface $input): array;

    /** @param array<string,mixed> $data the fields from extract() */
    abstract protected function writeHuman(OutputInterface $output, array $data): void;

    /** Commands whose data is never cached ask the node every time and take no --live. */
    protected function alwaysAsksNode(): bool
    {
        return false;
    }

    /** Commands that make sense fleet-wide run on every enabled server without a server argument. */
    protected function coversFleet(): bool
    {
        return false;
    }

    /** Commands about one application take an `app` argument and report it as the JSON `app` field. */
    protected function requiresApp(): bool
    {
        return false;
    }

    protected function configure(): void
    {
        if ($this->coversFleet()) {
            $this->addArgument('server', InputArgument::OPTIONAL, 'Server name from servers.json; all enabled servers without it');
        } else {
            $this->addArgument('server', InputArgument::REQUIRED, 'Server name from servers.json');
        }
        if ($this->requiresApp()) {
            $this->addArgument('app', InputArgument::REQUIRED, 'Application name, see apps list');
        }
        if (!$this->alwaysAsksNode()) {
            $this->addOption('live', null, InputOption::VALUE_NONE, 'Ask the node instead of reading the cache');
        }
        parent::configure();
        $this->setHelp($this->alwaysAsksNode()
            ? 'Always asks the node: this data is never cached.'
            : <<<'HELP'
                Reads the copy that cron and the dashboard cached and never contacts the node, so it is
                safe to call often. --live asks the node instead. The JSON `cache` block
                says how old the copy is; a copy past its TTL exits 1 (warning).
                HELP);
    }

    /** The `app` argument, checked because it becomes a node URL path segment. */
    protected static function appArgument(InputInterface $input): string
    {
        return CliInput::appName((string) $input->getArgument('app'));
    }

    /** CacheType::None with --live, else the given type. */
    protected static function cacheType(bool $live, CacheType $cached): CacheType
    {
        return $live ? CacheType::None : $cached;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Reject bad input before reading config or asking a node; fetch() and extract() parse it again.
        if ($this->requiresApp()) {
            self::appArgument($input);
        }
        if ($input->hasOption('limit')) {
            CliInput::limit($input);
        }
        if ($input->hasOption('since')) {
            CliInput::since($input, new DateTimeImmutable());
        }
        $reader = new NodeDatasetReader($this->cli, $this->cli->load());
        $servers = $reader->servers();
        $server = $input->getArgument('server');

        if (!is_string($server)) {
            return $this->executeFleet($input, $output, $reader, $servers);
        }

        if (!isset($servers[$server])) {
            return CliOutput::fail($input, $output, "Server '{$server}' is not in servers.json.", payload: ['servers' => array_keys($servers)]);
        }

        $outcome = $this->readServer($input, $reader, $server, $servers[$server]);
        if ($outcome['error'] !== null) {
            return CliOutput::fail($input, $output, $outcome['error'], payload: $outcome['fields']);
        }

        return $this->report($input, $output, $outcome['level'], $outcome['fields'], function () use ($output, $server, $outcome): void {
            $this->writeHuman($output, $outcome['data']);
            $this->writeFooter($output, $server, $outcome['fields']);
        });
    }

    /**
     * Without a server argument: every enabled server, one entry each. A failing server does
     * not hide the others; the worst level wins.
     *
     * @param array<string,array<string,mixed>> $servers
     */
    private function executeFleet(InputInterface $input, OutputInterface $output, NodeDatasetReader $reader, array $servers): int
    {
        $outcomes = [];
        foreach ($servers as $name => $serverConfig) {
            if (!ServerConfigState::isDisabled($serverConfig)) {
                $outcomes[$name] = $this->readServer($input, $reader, $name, $serverConfig);
            }
        }

        $entries = [];
        foreach ($outcomes as $name => $outcome) {
            $entries[] = ['server' => $name, 'status' => $outcome['level']->label()]
                + ($outcome['error'] !== null ? ['error' => $outcome['error']] : [])
                + $outcome['fields'];
        }
        $level = StatusLevel::worst(array_column($outcomes, 'level'));

        return $this->report($input, $output, $level, ['servers' => $entries], function () use ($output, $outcomes): void {
            if ($outcomes === []) {
                $output->writeln('No enabled servers in servers.json.');
            }
            foreach ($outcomes as $name => $outcome) {
                $output->writeln("<info>== {$name}</info>");
                if ($outcome['error'] !== null) {
                    $output->writeln('<error>' . OutputFormatter::escape($outcome['error']) . '</error>');
                } else {
                    $this->writeHuman($output, $outcome['data']);
                    $this->writeFooter($output, $name, $outcome['fields']);
                }
            }
        });
    }

    /**
     * One server's dataset. `fields` are the JSON fields after `status`: the provenance and,
     * without an error, `data`, the fields from extract().
     *
     * @param array<string,mixed> $serverConfig
     *
     * @return array{level:StatusLevel,error:null|string,fields:array<string,mixed>,data:array<string,mixed>}
     */
    private function readServer(InputInterface $input, NodeDatasetReader $reader, string $server, array $serverConfig): array
    {
        $live = $this->alwaysAsksNode() || $input->getOption('live') === true;
        $dataset = $reader->read(
            $server,
            $serverConfig,
            $live,
            $this->datasetName($input),
            fn (ServerDataCollectorInterface $collector): ?array => $this->fetch($collector, $live, $input),
        );
        if ($dataset->error !== null) {
            return ['level' => $dataset->level(), 'error' => $dataset->error, 'fields' => $dataset->fields(), 'data' => []];
        }

        $app = $this->requiresApp() ? ['app' => self::appArgument($input)] : [];
        $data = $app + $this->extract($dataset->result, $input);

        return ['level' => $dataset->level(), 'error' => null, 'fields' => $dataset->fields() + $data, 'data' => $data];
    }

    /** @param array<string,mixed> $fields */
    private function writeFooter(OutputInterface $output, string $server, array $fields): void
    {
        $cache = is_array($fields['cache'] ?? null) ? $fields['cache'] : [];
        $datasetName = is_array($fields['source'] ?? null) ? (string) ($fields['source']['dataset'] ?? '') : '';
        $output->writeln(sprintf('<comment>%s · %s · %s</comment>', $server, $datasetName, Provenance::describeCache($cache)));
    }
}
