<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use GuzzleHttp\Client;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\Loader\ChannelsConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\ChannelsConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
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
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Throwable;
use Twig\Loader\FilesystemLoader;

/**
 * Alarm delivery channels: the channels from channels.json and their senders.
 */
final readonly class AlarmChannelRuntime
{
    public function __construct(
        public ChannelsConfig $channels,
        private PathResolver $paths,
        private ApplicationConfig $appConfig,
        private MailerInterface $mailer,
        private LoggerInterface $log,
    ) {}

    /**
     * Load channels.json and record its problems as events.
     * Without $events the problems are dropped; `bin/mata config validate` reports them.
     */
    public static function build(
        PathResolver $paths,
        ApplicationConfig $appConfig,
        LoggerInterface $log,
        ?EventManager $events = null,
    ): self {
        $loaded = (new ChannelsConfigLoader())->load($paths->configPath);
        $events?->recordConfigProblems($loaded->problems);

        return new self(
            channels: $loaded->section,
            paths: $paths,
            appConfig: $appConfig,
            mailer: new Mailer(Transport::fromDsn((string) $appConfig->mail->dsn)),
            log: $log,
        );
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
            $this->serverPresentationConfig(),
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

    /**
     * Server colors and environments for alarm presentation. Alarms still render without them.
     *
     * @return array<string,mixed>
     */
    private function serverPresentationConfig(): array
    {
        try {
            $contents = file_get_contents($this->paths->getConfigPath(ServersConfigLoader::SERVERS_FILE));
            $servers = is_string($contents) ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : [];
            if (!is_array($servers)) {
                throw new RuntimeException('Server configuration must contain a JSON object.');
            }

            return $servers;
        } catch (Throwable $exception) {
            $this->log->warning('Alarm server presentation unavailable; rendering without server color or environment fallback.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}
