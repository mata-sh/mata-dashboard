<?php

declare(strict_types=1);

namespace Mata\Controllers;

use Exception;
use InvalidArgumentException;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Constants\CacheTypes;
use Mata\Core\App;
use Mata\Core\Session;
use Mata\Http\Request;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Responses\Response;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Audit\AuditAggregator;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Utils\Tools;
use Mata\Utils\TypeConverter;
use Mata\Views\AuditRenderer;
use Monolog\Logger;
use Twig\Environment;

/**
 * Serves cached Composer audit data and runs requested node audits.
 */
class AuditController extends BaseController
{
    private ApplicationConfig $appConfig;
    private CacheStoreInterface $cacheStore;
    private ApiCacheKeyFactory $cacheKeyFactory;
    private CacheTtlResolver $ttlResolver;
    private Endpoints $endpoints;
    private AuditRenderer $auditRenderer;
    private AuditAggregator $auditAggregator;

    /** @var array<string,ServerDataCollectorInterface> */
    private array $serverDataCollectors = [];

    /** @var array<string,mixed> */
    private array $serversConfig;

    /**
     * @param array<string,mixed> $serversConfig
     */
    public function __construct(
        App $app,
        Tools $tools,
        Environment $twig,
        Logger $logger,
        ApplicationConfig $appConfig,
        CacheStoreInterface $cacheStore,
        ApiCacheKeyFactory $cacheKeyFactory,
        CacheTtlResolver $ttlResolver,
        Endpoints $endpoints,
        array $serversConfig,
        ?Request $request = null,
    ) {
        $request ??= new Request();
        parent::__construct($request);
        $this->logger = $logger;
        $this->appConfig = $appConfig;
        $this->cacheStore = $cacheStore;
        $this->cacheKeyFactory = $cacheKeyFactory;
        $this->ttlResolver = $ttlResolver;
        $this->endpoints = $endpoints;
        $this->serversConfig = $serversConfig;
        $this->auditRenderer = new AuditRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->auditAggregator = new AuditAggregator();

        foreach ($this->serversConfig as $server => $serverConfig) {
            try {
                if (!is_array($serverConfig)) {
                    throw new InvalidArgumentException("Server {$server} configuration is invalid");
                }
                $this->serverDataCollectors[$server] = $this->createServerDataCollector($server, $serverConfig);
            } catch (Exception $e) {
                $this->logger->error("{$server}", [
                    'msg' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }
    }

    public function renderAuditSection(): Response
    {
        $summary = $this->buildSummary();
        if ($this->request->queryString('part') === 'summary') {
            return new HtmlResponse($this->auditRenderer->renderSummaryAndTargets($summary, $this->selectedTargets()));
        }

        return new HtmlResponse($this->auditRenderer->renderSection($summary, [
            'audit_section' => $this->endpoints->url('audit_section_render'),
            'audit_run' => $this->endpoints->url('audit_run_render'),
        ]));
    }

    public function runAudit(): Response
    {
        $targets = [];
        foreach ($this->request->postList('targets') as $target) {
            if (!is_string($target)) {
                continue;
            }
            $parts = explode('|', $target, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$server, $app] = $parts;
            if (!$this->isValidTarget($server, $app)) {
                continue;
            }
            $targets[] = [
                'server' => $server,
                'app' => $app,
                'url' => $this->endpoints->url('audit_run_app_render', ['server' => $server, 'app' => $app]),
            ];
        }

        return new HtmlResponse($this->auditRenderer->renderRunPlaceholders($targets));
    }

    public function runAuditApp(string $server, string $app): Response
    {
        if (!$this->isValidTarget($server, $app)) {
            return new HtmlResponse('', 404, ['HX-Trigger' => 'auditChipDone']);
        }

        // Release PHP's session lock so htmx fan-out requests can run concurrently.
        Session::close();
        $response = $this->serverDataCollectors[$server]->fetchAppComposerAudit($app, CacheTypes::NONE);

        return (new HtmlResponse($this->auditRenderer->renderChipResult($server, $app, $response)))
            ->addHeader('HX-Trigger', 'auditChipDone');
    }

    /** @return array<string,mixed> */
    private function buildSummary(): array
    {
        $serverAppAudits = [];
        foreach ($this->serverDataCollectors as $server => $collector) {
            $serverAppAudits[$server] = [];
            foreach ($this->appNames($collector->getApps()) as $app) {
                $serverAppAudits[$server][$app] = $collector->getCachedAppComposerAudit($app);
            }
        }

        return $this->auditAggregator->summarize($serverAppAudits);
    }

    /** @return null|array<int,string> Null when no selection was included in the request. */
    private function selectedTargets(): ?array
    {
        if (!$this->request->hasQuery('targets_present')) {
            return null;
        }

        return $this->request->queryList('targets');
    }

    private function isValidTarget(string $server, string $app): bool
    {
        if (!isset($this->serversConfig[$server], $this->serverDataCollectors[$server])) {
            return false;
        }

        return in_array($app, $this->appNames($this->serverDataCollectors[$server]->getApps()), true);
    }

    /**
     * @param null|array<string,mixed> $appsResponse
     *
     * @return array<int,string>
     */
    private function appNames(?array $appsResponse): array
    {
        $apps = $appsResponse['result'] ?? null;
        if (!is_array($apps)) {
            return [];
        }

        $names = [];
        foreach ($apps as $name => $app) {
            if (is_string($name) && $name !== '' && is_array($app) && ($app['composer'] ?? false) === true) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string,mixed> $serverConfig
     */
    private function createServerDataCollector(string $server, array $serverConfig): ServerDataCollectorInterface
    {
        if ($this->appConfig->environment->env === 'demo') {
            return new MockServerDataCollector(
                $this->logger,
                $this->ttlResolver,
                new NodeResponseNormalizer($this->logger),
                $server,
                $serverConfig
            );
        }
        if (!isset($serverConfig['api_key'], $serverConfig['api_url'])) {
            throw new InvalidArgumentException("Server {$server} missing required config");
        }

        $apiClient = new NodeApiClient(
            $this->logger,
            $serverConfig['api_url'],
            $server,
            $serverConfig['api_key'],
            10,
            true,
            TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false,
            TypeConverter::toBool($serverConfig['suppress_http_warning'] ?? null) ?? false
        );

        return new ServerDataCollector(
            $this->logger,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->ttlResolver,
            $apiClient,
            new NodeResponseNormalizer($this->logger)
        );
    }
}
