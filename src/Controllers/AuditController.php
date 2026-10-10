<?php

declare(strict_types=1);

namespace Mata\Controllers;

use Mata\Core\App;
use Mata\Core\Session;
use Mata\Http\Request;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Responses\Response;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Audit\AuditAggregator;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Utils\Tools;
use Mata\Views\AuditRenderer;
use Monolog\Logger;
use Twig\Environment;

/**
 * Serves cached Composer audit data and runs requested node audits.
 */
class AuditController extends BaseController
{
    private Endpoints $endpoints;
    private AuditRenderer $auditRenderer;
    private AuditAggregator $auditAggregator;

    /** @var array<string,ServerDataCollectorInterface> */
    private array $serverDataCollectors;

    /**
     * @param array<string,ServerDataCollectorInterface> $serverDataCollectors Collectors built at bootstrap, keyed by server
     */
    public function __construct(
        App $app,
        Tools $tools,
        Environment $twig,
        Logger $logger,
        Endpoints $endpoints,
        array $serverDataCollectors,
        Request $request,
    ) {
        parent::__construct($request);
        $this->logger = $logger;
        $this->endpoints = $endpoints;
        $this->serverDataCollectors = $serverDataCollectors;
        $this->auditRenderer = new AuditRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->auditAggregator = new AuditAggregator();
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
        $response = $this->serverDataCollectors[$server]->fetchAppComposerAudit($app, CacheType::None);

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
        if (!isset($this->serverDataCollectors[$server])) {
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
}
