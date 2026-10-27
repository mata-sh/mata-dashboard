<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Bootstrap;

use Mata\Bootstrap\AlarmChannelRuntime;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\ChannelsConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Services\Alarm\Delivery\AlarmEmailSender;
use Mata\Services\Alarm\Delivery\AlarmWebhookSender;
use Mata\Services\Event\Dto\EventRecordResult;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use Mata\Tests\TestCase;
use Mata\Utils\PathResolver;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;

/**
 * @covers \Mata\Bootstrap\AlarmChannelRuntime
 *
 * @internal
 */
final class AlarmChannelRuntimeTest extends TestCase
{
    public function testDefaultRulesNeedOnlyTheBuiltInEmailChannel(): void
    {
        [$events, $recorded] = $this->events();

        $this->runtime(ChannelsConfig::empty())->reportUnknownRuleChannels(NotificationRulesConfig::default(), $events);

        $this->assertSame([], $recorded->messages);
    }

    public function testRuleChannelsWithoutASenderAreReportedOnce(): void
    {
        [$events, $recorded] = $this->events();
        $channels = $this->telegramChannels();
        $rules = new NotificationRulesConfig(
            ['enabled' => true, 'channels' => ['email', 'telegram']],
            ['node_unreachable' => ['channels' => ['telgram']]],
            ['shop' => ['fatal_errors_occurring' => ['channels' => ['telgram']]]],
        );

        $this->runtime($channels)->reportUnknownRuleChannels($rules, $events);

        $this->assertSame([
            'notification_rules.json routes alarms to channel telgram, which is not configured; its deliveries are skipped. Configured channels: email, telegram.',
        ], $recorded->messages);
    }

    public function testLimitsForUnconfiguredChannelsAreReportedOnce(): void
    {
        [$events, $recorded] = $this->events();
        $limits = AlarmRateLimitsConfig::fromArray(
            [
                'channels' => ['default' => ['per_minute' => 10], 'email' => ['per_minute' => 5], 'telgram' => ['per_minute' => 1]],
                'overrides' => ['osira' => ['telegram' => ['per_minute' => 2], 'telgram' => ['per_minute' => 1]]],
            ],
            static function (string $message): void {},
        );

        $this->runtime($this->telegramChannels())->reportUnknownLimitChannels($limits, $events);

        $this->assertSame([
            'limits.json sets limits for channel telgram, which is not configured; they are never applied. Configured channels: email, telegram.',
        ], $recorded->messages);
    }

    public function testEveryConfiguredChannelGetsASender(): void
    {
        $appPath = $this->createTempDirectory();
        mkdir($appPath . '/templates');
        mkdir($appPath . '/config');
        file_put_contents($appPath . '/config/servers.json', '{}');

        try {
            $channels = ChannelsConfig::fromArray(
                ['channels' => [
                    'telegram' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_TELEGRAM_URL', 'template' => 'telegram'],
                    'security' => ['type' => 'email', 'recipients' => ['security@example.com']],
                ]],
                static fn (string $name): string => 'https://api.telegram.org/botX/sendMessage',
                static function (string $message): void {},
            );
            $senders = $this->runtime($channels, PathResolver::fromAppPath($appPath))->senders();

            $this->assertSame(['email', 'security', 'telegram'], array_keys($senders));
            $this->assertInstanceOf(AlarmEmailSender::class, $senders['email']);
            $this->assertInstanceOf(AlarmEmailSender::class, $senders['security']);
            $this->assertInstanceOf(AlarmWebhookSender::class, $senders['telegram']);
        } finally {
            $this->cleanupTempFiles([$appPath]);
        }
    }

    private function runtime(ChannelsConfig $channels, ?PathResolver $paths = null): AlarmChannelRuntime
    {
        return new AlarmChannelRuntime(
            $channels,
            $paths ?? PathResolver::fromAppPath(dirname(__DIR__, 4)),
            ApplicationConfigFactory::create(),
            $this->createStub(MailerInterface::class),
            new NullLogger(),
        );
    }

    private function telegramChannels(): ChannelsConfig
    {
        return ChannelsConfig::fromArray(
            ['channels' => ['telegram' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_TELEGRAM_URL', 'template' => 'telegram']]],
            static fn (string $name): string => 'https://api.telegram.org/botX/sendMessage',
            static function (string $message): void {},
        );
    }

    /** @return array{EventManager, object{messages: list<string>}} */
    private function events(): array
    {
        $recorded = new class () {
            /** @var list<string> */
            public array $messages = [];
        };
        $recorder = $this->createStub(EventRecorderService::class);
        $recorder->method('recordEvent')->willReturnCallback(
            static function (string $type, string $message) use ($recorded): EventRecordResult {
                $recorded->messages[] = $message;

                return new EventRecordResult(1, null, null, null, null, null);
            },
        );

        return [new EventManager(ApplicationConfigFactory::create(), eventRecorder: $recorder), $recorded];
    }
}
