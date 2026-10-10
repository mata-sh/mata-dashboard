<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Monitoring\Section\ChannelsConfig;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @covers \Mata\Config\Monitoring\Section\ChannelsConfig
 *
 * @internal
 */
final class ChannelsConfigTest extends TestCase
{
    private const TELEGRAM_URL = 'https://api.telegram.org/bot123456:AAH-secret-token/sendMessage';

    /** @var list<string> */
    private array $reports = [];

    public function testValidChannelResolvesItsUrlFromTheEnvironment(): void
    {
        $config = $this->load(['telegram' => [
            'type' => 'webhook',
            'url_env' => 'WEBHOOK_TELEGRAM_URL',
            'template' => 'telegram',
            'params' => ['chat_id' => -1001234567890],
            'timeout_seconds' => 8,
        ]]);

        $this->assertSame(['email', 'telegram'], $config->names());
        $channel = $config->webhooks['telegram'];
        $this->assertSame(self::TELEGRAM_URL, $channel->url);
        $this->assertSame('telegram', $channel->template);
        $this->assertSame(['chat_id' => -1001234567890], $channel->params);
        $this->assertSame(8, $channel->timeoutSeconds);
        $this->assertSame([], $this->reports);
    }

    public function testDefaultsApplyAndDisabledChannelsAreSkippedSilently(): void
    {
        $config = $this->load([
            'telegram' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_TELEGRAM_URL', 'template' => 'telegram'],
            'slack' => ['type' => 'webhook', 'enabled' => false, 'url_env' => 'WEBHOOK_SLACK_URL', 'template' => 'generic'],
        ]);

        $this->assertSame(['email', 'telegram'], $config->names());
        $this->assertSame(5, $config->webhooks['telegram']->timeoutSeconds);
        $this->assertSame([], $config->webhooks['telegram']->params);
        $this->assertSame([], $this->reports);
    }

    public function testInvalidChannelIsReportedAndSkippedWhileValidOnesLoad(): void
    {
        $config = $this->load([
            'telegram' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_TELEGRAM_URL', 'template' => 'telegram'],
            'ops' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_UNSET_URL', 'template' => 'generic'],
        ]);

        $this->assertSame(['email', 'telegram'], $config->names());
        $this->assertSame(['channels.ops: environment variable WEBHOOK_UNSET_URL is not set. Skipping channel.'], $this->reports);
    }

    /** @param array<string,mixed> $channels */
    #[DataProvider('invalidChannels')]
    public function testInvalidChannelEntryIsReportedAndSkipped(array $channels, string $expected): void
    {
        $config = $this->load($channels);

        $this->assertSame(['email'], $config->names());
        $this->assertCount(1, $this->reports);
        $this->assertStringContainsString($expected, $this->reports[0]);
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invalidChannels(): iterable
    {
        $valid = ['type' => 'webhook', 'url_env' => 'WEBHOOK_TELEGRAM_URL', 'template' => 'telegram'];

        yield 'reserved email name' => [['email' => $valid], 'invalid or reserved channel name'];

        yield 'invalid name' => [['Tele gram' => $valid], 'invalid or reserved channel name'];

        yield 'list entry' => [['telegram' => ['a', 'b']], 'must be an object'];

        yield 'missing type' => [['telegram' => ['url_env' => 'WEBHOOK_TELEGRAM_URL', 'template' => 'telegram']], 'type must be "webhook"'];

        yield 'unknown type' => [['telegram' => ['type' => 'sms'] + $valid], 'type must be "webhook"'];

        yield 'unknown key' => [['telegram' => $valid + ['url' => 'https://x']], 'unknown key url'];

        yield 'non boolean enabled' => [['telegram' => $valid + ['enabled' => 'yes']], 'enabled must be a boolean'];

        yield 'lowercase env name' => [['telegram' => ['url_env' => 'webhook_url'] + $valid], 'url_env must name'];

        yield 'template path traversal' => [['telegram' => ['template' => '../emails/alarm'] + $valid], 'template must name'];

        yield 'string timeout' => [['telegram' => $valid + ['timeout_seconds' => '5']], 'timeout_seconds must be an integer'];

        yield 'timeout above bound' => [['telegram' => $valid + ['timeout_seconds' => 31]], 'timeout_seconds must be an integer'];

        yield 'nested param' => [['telegram' => $valid + ['params' => ['chat_id' => ['id' => 1]]]], 'params must be an object'];

        yield 'insecure url' => [['local' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_HTTP_URL'] + $valid], 'must use https'];

        yield 'userinfo url' => [['local' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_USERINFO_URL'] + $valid], 'without userinfo'];
    }

    public function testReportsNeverContainTheWebhookUrl(): void
    {
        $this->load([
            'a' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_HTTP_URL', 'template' => 'generic'],
            'b' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_USERINFO_URL', 'template' => 'generic'],
        ]);

        $this->assertCount(2, $this->reports);
        foreach ($this->reports as $report) {
            $this->assertStringNotContainsString('secret', $report);
        }
    }

    public function testInsecureUrlIsAllowedWithTheOverride(): void
    {
        $config = $this->load(['local' => ['type' => 'webhook', 'url_env' => 'WEBHOOK_HTTP_URL', 'template' => 'generic']], ['WEBHOOK_ALLOW_INSECURE' => 'true']);

        $this->assertSame(['email', 'local'], $config->names());
    }

    public function testNonObjectChannelsAreReportedAndYieldNoChannels(): void
    {
        $config = ChannelsConfig::fromArray(['channels' => ['telegram']], $this->env(), $this->reporter());

        $this->assertSame(['email'], $config->names());
        $this->assertSame(['channels must be an object; only email is available.'], $this->reports);
    }

    /**
     * @param array<string,mixed>  $channels
     * @param array<string,string> $env
     */
    private function load(array $channels, array $env = []): ChannelsConfig
    {
        return ChannelsConfig::fromArray(['channels' => $channels], $this->env($env), $this->reporter());
    }

    /**
     * @param array<string,string> $extra
     *
     * @return callable(string):?string
     */
    private function env(array $extra = []): callable
    {
        $env = $extra + [
            'WEBHOOK_TELEGRAM_URL' => self::TELEGRAM_URL,
            'WEBHOOK_HTTP_URL' => 'http://hooks.internal/secret-path',
            'WEBHOOK_USERINFO_URL' => 'https://user:secret@hooks.example.com/',
        ];

        return static fn (string $name): ?string => $env[$name] ?? null;
    }

    /** @return callable(string):void */
    private function reporter(): callable
    {
        return function (string $message): void {
            $this->reports[] = $message;
        };
    }
}
