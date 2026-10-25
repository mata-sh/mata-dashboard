<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use GuzzleHttp\Client;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\Loader\AdvisoriesConfigLoader;
use Mata\Config\Monitoring\Section\AdvisoriesConfig;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySourceType;
use Mata\Database\DatabaseInterface;
use Mata\Models\Advisory\AdvisoryRepository;
use Mata\Models\Advisory\AdvisorySourceStateRepository;
use Mata\Services\Advisory\AdvisoryDigestMailer;
use Mata\Services\Advisory\AdvisoryPollWatchdog;
use Mata\Services\Advisory\AdvisoryRelevanceFilter;
use Mata\Services\Advisory\AdvisorySourcePoller;
use Mata\Services\Advisory\Fetch\CsafAdvisoryFetcher;
use Mata\Services\Advisory\Fetch\FeedAdvisoryFetcher;
use Mata\Services\Advisory\Fetch\GithubAdvisoryFetcher;
use Mata\Services\Advisory\Fetch\ImapAdvisoryFetcher;
use Mata\Services\Advisory\Fetch\OsvAdvisoryFetcher;
use Mata\Services\Advisory\Report\AdvisoryReportBuilder;
use Mata\Services\Advisory\Report\AdvisoryReportPdfWriter;
use Mata\Services\Advisory\Report\AdvisoryReportPresenter;
use Mata\Services\Advisory\Report\AdvisoryReportRenderer;
use Mata\Services\Event\EventManager;
use Mata\Utils\AppVersion;
use Mata\Utils\PathResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;

/**
 * Security advisory monitoring: advisories.json and the services built from it, shared by
 * cron/advisories_*.php, bin/mata advisory commands and the Advisories page.
 */
final readonly class AdvisoryRuntime
{
    public function __construct(
        public AdvisoriesConfig $config,
        public AdvisoryRepository $advisories,
        public AdvisorySourceStateRepository $states,
        private PathResolver $paths,
        private ApplicationConfig $appConfig,
    ) {}

    /**
     * Load advisories.json and record its problems as events.
     * Without $events the problems are dropped; `bin/mata config validate` reports them.
     */
    public static function build(
        PathResolver $paths,
        ApplicationConfig $appConfig,
        DatabaseInterface $db,
        ?EventManager $events = null,
    ): self {
        $loaded = (new AdvisoriesConfigLoader())->load($paths->configPath);
        $events?->recordConfigProblems($loaded->problems);

        return new self(
            config: $loaded->section,
            advisories: new AdvisoryRepository($db),
            states: new AdvisorySourceStateRepository($db),
            paths: $paths,
            appConfig: $appConfig,
        );
    }

    public function poller(EventManager $events, LoggerInterface $log): AdvisorySourcePoller
    {
        $http = new Client();
        $userAgent = $this->userAgent();

        return new AdvisorySourcePoller(
            fetchers: [
                AdvisorySourceType::Feed->value => new FeedAdvisoryFetcher($http, $userAgent),
                AdvisorySourceType::Github->value => new GithubAdvisoryFetcher($http, $userAgent),
                AdvisorySourceType::Imap->value => new ImapAdvisoryFetcher(),
                AdvisorySourceType::Osv->value => new OsvAdvisoryFetcher($http, $userAgent),
                AdvisorySourceType::Csaf->value => new CsafAdvisoryFetcher($http, $userAgent, $log),
            ],
            advisories: $this->advisories,
            states: $this->states,
            filter: new AdvisoryRelevanceFilter(),
            eventManager: $events,
            logger: $log,
            failureWarningAfter: $this->config->failureWarningAfter,
        );
    }

    public function watchdog(EventManager $events): AdvisoryPollWatchdog
    {
        return new AdvisoryPollWatchdog($this->config, $events, $this->paths->cronLogsPath);
    }

    public function reportBuilder(): AdvisoryReportBuilder
    {
        return new AdvisoryReportBuilder($this->config, $this->advisories, $this->states);
    }

    public function reportRenderer(): AdvisoryReportRenderer
    {
        return new AdvisoryReportRenderer(
            $this->paths->templatesPath,
            new AdvisoryReportPresenter($this->appConfig->general->appName, $this->appConfig->mail->dashboardLink()),
            new AdvisoryReportPdfWriter($this->paths->appPath . '/' . AdvisoryReportPdfWriter::FONT_DIR),
        );
    }

    public function digestMailer(): AdvisoryDigestMailer
    {
        return new AdvisoryDigestMailer(
            new Mailer(Transport::fromDsn((string) $this->appConfig->mail->dsn)),
            $this->appConfig->mail,
            $this->config->digest,
            $this->reportRenderer(),
            $this->appConfig->general->appName,
        );
    }

    /** Identifies the dashboard to advisory sources, e.g. `MATA-Dashboard/1.4.0 (…; +https://mata.sh)`. */
    private function userAgent(): string
    {
        return sprintf(
            '%s-Dashboard/%s (security advisory monitoring; +https://mata.sh)',
            preg_replace('/[^A-Za-z0-9._-]+/', '', $this->appConfig->general->appName) ?: 'MATA',
            AppVersion::detect($this->paths->appPath) ?? 'dev',
        );
    }
}
