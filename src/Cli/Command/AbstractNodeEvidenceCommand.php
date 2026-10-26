<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use DateTimeImmutable;
use InvalidArgumentException;
use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Cli\Evidence;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Http\Security\RouteParameter;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheType;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Status\StatusLevel;
use Mata\Utils\TypeConverter;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
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
 * commands (serverOptional) report each server and exit with the worst level.
 */
abstract class AbstractNodeEvidenceCommand extends Command
{
    public function __construct(private readonly CliConfig $cli)
    {
        parent::__construct();
    }

    /** Names the data in errors and in the JSON `source`, e.g. 'logs'. */
    abstract protected function dataset(InputInterface $input): string;

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

    /** @param array<string,mixed> $data fields from extract() */
    abstract protected function writeHuman(OutputInterface $output, array $data): void;

    /** Commands whose data is never cached ask the node every time and take no --live. */
    protected function alwaysLive(): bool
    {
        return false;
    }

    /** Commands that make sense fleet-wide run on every enabled server without a server argument. */
    protected function serverOptional(): bool
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
        if ($this->serverOptional()) {
            $this->addArgument('server', InputArgument::OPTIONAL, 'Server name from servers.json; all enabled servers without it');
        } else {
            $this->addArgument('server', InputArgument::REQUIRED, 'Server name from servers.json');
        }
        if ($this->requiresApp()) {
            $this->addArgument('app', InputArgument::REQUIRED, 'Application name, see apps list');
        }
        if (!$this->alwaysLive()) {
            $this->addOption('live', null, InputOption::VALUE_NONE, 'Ask the node instead of reading the cache');
        }
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
        $this->setHelp($this->alwaysLive()
            ? 'Always asks the node: this data is never cached.'
            : <<<'HELP'
                Reads the copy that cron and the dashboard cached and never contacts the node, so it is
                safe to call often. --live asks the node instead. The JSON `cache` block
                says how old the copy is; a copy past its TTL exits 1 (warning).
                HELP);
    }

    /**
     * The `app` argument, checked because it becomes a node URL path segment.
     *
     * @throws InvalidArgumentException for a name outside the route charset or starting with a dot
     */
    protected static function app(InputInterface $input): string
    {
        $app = (string) $input->getArgument('app');
        RouteParameter::assertSafe($app, "Invalid application name '{$app}'.");
        if (str_starts_with($app, '.')) {
            throw new InvalidArgumentException("Invalid application name '{$app}'.");
        }

        return $app;
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
            self::app($input);
        }
        if ($input->hasOption('limit')) {
            Evidence::limit($input);
        }
        if ($input->hasOption('since')) {
            Evidence::since($input, new DateTimeImmutable());
        }
        $config = $this->cli->load();
        $server = $input->getArgument('server');
        $servers = (new ServersConfigLoader(fallbackOnFailure: true))->load($this->cli->configPath)->section->values;

        if (!is_string($server)) {
            return $this->executeFleet($input, $output, $config, $servers);
        }

        $serverConfig = self::isServerName($server) ? ($servers[$server] ?? null) : null;
        if (!is_array($serverConfig)) {
            $known = [];
            foreach (array_keys($servers) as $name) {
                if (self::isServerName((string) $name)) {
                    $known[] = (string) $name;
                }
            }

            return CliOutput::fail($input, $output, "Server '{$server}' is not in servers.json.", payload: ['servers' => $known]);
        }

        $read = $this->read($input, $config, $server, $serverConfig);
        if ($read['error'] !== null) {
            return CliOutput::fail($input, $output, $read['error'], payload: $read['document']);
        }

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => $read['level']->label()] + $read['document']);
        } else {
            $this->writeHuman($output, $read['data']);
            $this->writeFooter($output, $server, $read['document']);
        }

        return $read['level']->value;
    }

    /**
     * Without a server argument: every enabled server, one entry each. A failing server does
     * not hide the others; the worst level wins.
     *
     * @param array<string,mixed> $servers
     */
    private function executeFleet(InputInterface $input, OutputInterface $output, ApplicationConfig $config, array $servers): int
    {
        $entries = [];
        $levels = [];
        foreach ($servers as $name => $serverConfig) {
            $name = (string) $name;
            if (!self::isServerName($name) || !is_array($serverConfig) || ServerConfigState::isDisabled($serverConfig)) {
                continue;
            }
            $read = $this->read($input, $config, $name, $serverConfig);
            $levels[] = $read['level'];
            $entries[] = ['server' => $name, 'status' => $read['level']->label()]
                + ($read['error'] !== null ? ['error' => $read['error']] : [])
                + $read['document'];

            if (!$input->getOption('json')) {
                $output->writeln("<info>== {$name}</info>");
                if ($read['error'] !== null) {
                    $output->writeln('<error>' . OutputFormatter::escape($read['error']) . '</error>');
                } else {
                    $this->writeHuman($output, $read['data']);
                    $this->writeFooter($output, $name, $read['document']);
                }
            }
        }
        $level = StatusLevel::worst($levels);

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => $level->label(), 'servers' => $entries]);
        } elseif ($entries === []) {
            $output->writeln('No enabled servers in servers.json.');
        }

        return $level->value;
    }

    /**
     * One server's data. `document` holds the JSON fields after `status`: the provenance and,
     * without an error, the command's data fields.
     *
     * @param array<string,mixed> $serverConfig
     *
     * @return array{level:StatusLevel,error:null|string,document:array<string,mixed>,data:array<string,mixed>}
     */
    private function read(InputInterface $input, ApplicationConfig $config, string $server, array $serverConfig): array
    {
        $live = $this->alwaysLive() || $input->getOption('live') === true;
        $dataset = $this->dataset($input);
        $demo = $config->environment->env === 'demo';

        if ($live && !$demo && trim((string) ($serverConfig['api_key'] ?? '')) === '') {
            return self::failure("Server '{$server}' has no api_key in servers.secrets.json.");
        }

        try {
            $collector = $this->collector($config, $server, $serverConfig, $live);
        } catch (RuntimeException $exception) {
            // e.g. an http:// api_url without allow_http
            return self::failure($exception->getMessage());
        }
        $response = $this->fetch($collector, $live, $input);
        if ($response === null) {
            return self::failure($live
                ? "The node of '{$server}' did not answer. Check it with: bin/mata server test {$server}"
                : "No cached copy of the {$dataset} of '{$server}' yet. Pass --live to ask the node.");
        }

        $evidence = Evidence::fromNode($server, $dataset, $response, $demo, new DateTimeImmutable());
        $nodeError = $response['error'] ?? null;
        if (is_array($nodeError)) {
            $message = is_string($nodeError['message'] ?? null) ? $nodeError['message'] : 'The node returned an error.';

            return self::failure($message, $evidence + ['node_error' => $nodeError]);
        }

        $app = $this->requiresApp() ? ['app' => self::app($input)] : [];
        $data = $app + $this->extract($response['result'] ?? null, $input);
        $notice = isset($response['notice']) ? ['notice' => $response['notice']] : [];

        return [
            'level' => $evidence['cache']['stale'] ? StatusLevel::Warning : StatusLevel::Ok,
            'error' => null,
            'document' => $evidence + $notice + $data,
            'data' => $data,
        ];
    }

    /** servers.json keys starting with an underscore, like `_README`, document the file. */
    private static function isServerName(string $name): bool
    {
        return !str_starts_with($name, '_');
    }

    /**
     * A read() result without data.
     *
     * @param array<string,mixed> $document provenance to report alongside the error
     *
     * @return array{level:StatusLevel,error:string,document:array<string,mixed>,data:array{}}
     */
    private static function failure(string $error, array $document = []): array
    {
        return [
            'level' => StatusLevel::Error,
            'error' => $error,
            'document' => $document,
            'data' => [],
        ];
    }

    /** @param array<string,mixed> $document */
    private function writeFooter(OutputInterface $output, string $server, array $document): void
    {
        $cache = is_array($document['cache'] ?? null) ? $document['cache'] : [];
        $dataset = is_array($document['source'] ?? null) ? (string) ($document['source']['dataset'] ?? '') : '';
        $output->writeln(sprintf('<comment>%s · %s · %s</comment>', $server, $dataset, Evidence::describeCache($cache)));
    }

    /** @param array<string,mixed> $serverConfig */
    private function collector(ApplicationConfig $config, string $server, array $serverConfig, bool $live): ServerDataCollectorInterface
    {
        $logger = new NullLogger();
        $ttl = new CacheTtlResolver((new TtlConfigLoader(fallbackOnFailure: true))->load($this->cli->configPath)->section);
        $normalizer = new NodeResponseNormalizer($logger);

        if ($config->environment->env === 'demo') {
            return new MockServerDataCollector($logger, $ttl, $normalizer, $server, $serverConfig, $config->demo);
        }

        return new ServerDataCollector(
            $logger,
            new FileCacheStore($logger, $this->cli->paths()->apiCachePath),
            new ApiCacheKeyFactory(),
            $ttl,
            new NodeApiClient(
                logger: $logger,
                apiBase: (string) ($serverConfig['api_url'] ?? ''),
                server: $server,
                apiKey: (string) ($serverConfig['api_key'] ?? ''),
                timeout: $config->nodeApi->timeoutSeconds,
                allowHttp: TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false,
                suppressHttpWarning: TypeConverter::toBool($serverConfig['suppress_http_warning'] ?? null) ?? false,
            ),
            $normalizer,
            cacheOnly: !$live,
        );
    }
}
