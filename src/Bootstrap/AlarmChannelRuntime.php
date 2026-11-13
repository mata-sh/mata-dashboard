<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use GuzzleHttp\Client;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\Loader\ChannelsConfigLoader;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadProblem;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\ChannelsConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Services\Alarm\Delivery\AlarmChannelSenderInterface;
use Mata\Services\Alarm\Delivery\AlarmEmailFactory;
use Mata\Services\Alarm\Delivery\AlarmEmailSender;
use Mata\Services\Alarm\Delivery\AlarmTemplateDataFactory;
use Mata\Services\Alarm\Delivery\AlarmWebhookPayloadFactory;
use Mata\Services\Alarm\Delivery\AlarmWebhookSender;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Utils\PathResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Twig\Loader\FilesystemLoader;

/**
 * Alarm delivery channels: the channels from channels.json and their senders.
 */
final readonly class AlarmChannelRuntime
{
    /**
     * @param ServersConfig $servers only presents alarms: each server's color and environment
     */
    public function __construct(
        public ChannelsConfig $channels,
        private ServersConfig $servers,
        private PathResolver $paths,
        private ApplicationConfig $appConfig,
        private MailerInterface $mailer,
        private LoggerInterface $log,
    ) {}

    /**
     * Load channels.json and record its problems as events.
     * Without $events the problems are dropped; `bin/mata config validate` reports them.
     *
     * @param ServersConfig $servers the loaded servers.json; see {@see presentationServers()} without a {@see MonitoringRuntime}
     */
    public static function build(
        PathResolver $paths,
        ApplicationConfig $appConfig,
        ServersConfig $servers,
        LoggerInterface $log,
        ?EventManager $events = null,
    ): self {
        $loaded = (new ChannelsConfigLoader())->load($paths->configPath);
        $events?->recordConfigProblems($loaded->problems);

        return new self(
            channels: $loaded->section,
            servers: $servers,
            paths: $paths,
            appConfig: $appConfig,
            mailer: new Mailer(Transport::fromDsn((string) $appConfig->mail->dsn)),
            log: $log,
        );
    }

    /**
     * servers.json for callers without a {@see MonitoringRuntime}. Server colors and environments
     * only present alarms, so a file that cannot be loaded leaves no servers and alarms still
     * render without them. Its problems are not recorded as events: one recorded here, where no
     * alarm processor is attached, would start the cooldown of the servers.json alarm that the
     * monitoring jobs raise.
     */
    public static function presentationServers(PathResolver $paths, LoggerInterface $log): ServersConfig
    {
        $loaded = (new ServersConfigLoader(fallbackOnFailure: true))->load($paths->configPath);
        if ($loaded->section->values === [] && $loaded->problems !== []) {
            $log->warning('Alarm server presentation unavailable; rendering without server color or environment fallback.', [
                'problems' => array_map(static fn (ConfigLoadProblem $problem): string => $problem->message, $loaded->problems),
            ]);
        }

        return $loaded->section;
    }

    /**
     * Warns about notification rules that route to a channel channels.json does not define.
     * The evaluators skip those deliveries; events dedupe the warning per cooldown window.
     */
    public function reportUnknownRuleChannels(NotificationRulesConfig $rules, EventManager $events): void
    {
        $configured = $this->channels->names();
        foreach (array_diff($rules->channels(), $configured) as $channel) {
            $message = "notification_rules.json routes alarms to channel {$channel}, which is not configured; "
                . 'its deliveries are skipped. Configured channels: ' . implode(', ', $configured) . '.';
            $events->handle(new EventCommand(
                type: 'notification_rules_unknown_channel',
                message: $message,
                level: 'warning',
                context: ['detail' => $message, 'channel' => $channel],
            ));
        }
    }

    /**
     * Warns about limits.json entries for a channel channels.json does not define, such as a typo,
     * since no delivery ever uses them.
     */
    public function reportUnknownLimitChannels(AlarmRateLimitsConfig $limits, EventManager $events): void
    {
        $limited = array_keys($limits->getChannelLimits());
        foreach ($limits->getServerOverrides() as $overrides) {
            $limited = [...$limited, ...array_keys($overrides)];
        }

        $configured = $this->channels->names();
        foreach (array_unique(array_diff($limited, $configured)) as $channel) {
            $message = "limits.json sets limits for channel {$channel}, which is not configured; they are never applied. "
                . 'Configured channels: ' . implode(', ', $configured) . '.';
            $events->handle(new EventCommand(
                type: 'limits_unknown_channel',
                message: $message,
                level: 'warning',
                context: ['detail' => $message, 'channel' => $channel],
            ));
        }
    }

    /**
     * One sender per configured channel: the built-in email sender plus one per enabled email
     * channel and webhook.
     *
     * @return array<string,AlarmChannelSenderInterface> keyed by channel name
     */
    public function senders(): array
    {
        $templates = new FilesystemLoader($this->paths->templatesPath);
        $templateData = new AlarmTemplateDataFactory(
            $this->appConfig->general->appName,
            $this->appConfig->mail->dashboardLink(),
            $this->servers->values,
        );

        $emailFactory = new AlarmEmailFactory($templates, $templateData, $this->appConfig->mail);
        $payloadFactory = new AlarmWebhookPayloadFactory($templates, $templateData);
        $http = new Client();

        $senders = [ChannelsConfig::EMAIL => new AlarmEmailSender($emailFactory, $this->mailer, $this->log)];
        foreach ($this->channels->emails as $name => $email) {
            $factory = new AlarmEmailFactory($templates, $templateData, $this->appConfig->mail, $email->recipients);
            $senders[$name] = new AlarmEmailSender($factory, $this->mailer, $this->log);
        }
        foreach ($this->channels->webhooks as $name => $webhook) {
            $senders[$name] = new AlarmWebhookSender($webhook, $payloadFactory, $http, $this->log);
        }

        return $senders;
    }
}
