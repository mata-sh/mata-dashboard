<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use GuzzleHttp\Client;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\Loader\ChannelsConfigLoader;
use Mata\Config\Monitoring\Section\ChannelsConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Services\Alarm\Delivery\AlarmChannelSenderInterface;
use Mata\Services\Alarm\Delivery\AlarmEmailFactory;
use Mata\Services\Alarm\Delivery\AlarmEmailSender;
use Mata\Services\Alarm\Delivery\AlarmPresenter;
use Mata\Services\Alarm\Delivery\AlarmTemplateDataFactory;
use Mata\Services\Alarm\Delivery\AlarmWebhookPayloadFactory;
use Mata\Services\Alarm\Delivery\AlarmWebhookSender;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Utils\PathResolver;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Composes alarm delivery channels for the evaluate and dispatch entry points. */
final class AlarmChannelBootstrap
{
    public static function loadChannels(PathResolver $paths, EventManager $events): ChannelsConfig
    {
        return (new ChannelsConfigLoader($events))->load($paths->configPath);
    }

    /**
     * Warns about notification rules that route to a channel channels.json does not define.
     * The evaluators skip those deliveries; events dedupe the warning per cooldown window.
     */
    public static function reportUnknownRuleChannels(NotificationRulesConfig $rules, ChannelsConfig $channels, EventManager $events): void
    {
        $configured = $channels->names();
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

    /** @return array<string,AlarmChannelSenderInterface> keyed by channel name */
    public static function senders(
        PathResolver $paths,
        ApplicationConfig $appConfig,
        MailerInterface $mailer,
        ChannelsConfig $channels,
        LoggerInterface $log,
    ): array {
        $templatesPath = $paths->appPath . '/templates';
        $presenter = new AlarmPresenter(self::serverPresentationConfig($paths, $log));

        $emailFactory = new AlarmEmailFactory(
            new Environment(new FilesystemLoader($templatesPath), ['autoescape' => 'name']),
            $appConfig->mail,
            $appConfig->general->appName,
            $presenter,
        );
        $senders = [ChannelsConfig::EMAIL => new AlarmEmailSender($mailer, $emailFactory, $log)];

        $payloadFactory = new AlarmWebhookPayloadFactory(
            $templatesPath,
            new AlarmTemplateDataFactory($appConfig->general->appName, $appConfig->mail->dashboardUrl, $presenter),
        );
        $http = new Client();
        foreach ($channels->webhooks as $name => $webhook) {
            $senders[$name] = new AlarmWebhookSender($webhook, $payloadFactory, $http, $log);
        }

        return $senders;
    }

    /**
     * Server colors and environments for alarm presentation. Alarms still render without them.
     *
     * @return array<string,mixed>
     */
    private static function serverPresentationConfig(PathResolver $paths, LoggerInterface $log): array
    {
        try {
            $contents = file_get_contents($paths->configPath . '/servers.json');
            $servers = is_string($contents) ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : [];
            if (!is_array($servers)) {
                throw new RuntimeException('Server configuration must contain a JSON object.');
            }

            return $servers;
        } catch (Throwable $exception) {
            $log->warning('Alarm server presentation unavailable; rendering without server color or environment fallback.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}
