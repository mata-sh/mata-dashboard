<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Bootstrap;

use Mata\Bootstrap\AlarmChannelBootstrap;
use Mata\Config\Monitoring\Section\ChannelsConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Services\Event\Dto\EventRecordResult;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Bootstrap\AlarmChannelBootstrap
 *
 * @internal
 */
final class AlarmChannelBootstrapTest extends TestCase
{
    public function testDefaultRulesNeedOnlyTheBuiltInEmailChannel(): void
    {
        [$events, $recorded] = $this->events();

        AlarmChannelBootstrap::reportUnknownRuleChannels(NotificationRulesConfig::default(), ChannelsConfig::empty(), $events);

        $this->assertSame([], $recorded->messages);
    }

    public function testRuleChannelsWithoutASenderAreReportedOnce(): void
    {
        [$events, $recorded] = $this->events();
        $channels = ChannelsConfig::fromArray(
            ['channels' => ['telegram' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_TELEGRAM_URL', 'template' => 'telegram']]],
            static fn (string $name): string => 'https://api.telegram.org/botX/sendMessage',
            static function (string $message): void {},
        );
        $rules = new NotificationRulesConfig(
            ['enabled' => true, 'channels' => ['email', 'telegram']],
            ['node_unreachable' => ['channels' => ['telgram']]],
            ['shop' => ['fatal_errors_occurring' => ['channels' => ['telgram']]]],
        );

        AlarmChannelBootstrap::reportUnknownRuleChannels($rules, $channels, $events);

        $this->assertSame([
            'notification_rules.json routes alarms to channel telgram, which is not configured; its deliveries are skipped. Configured channels: email, telegram.',
        ], $recorded->messages);
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
