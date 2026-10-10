<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use DateTimeImmutable;
use Mata\Config\Monitoring\Section\WebhookChannel;
use Mata\Services\Alarm\Delivery\AlarmPresenter;
use Mata\Services\Alarm\Delivery\AlarmTemplateDataFactory;
use Mata\Services\Alarm\Delivery\AlarmWebhookPayloadFactory;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Tests\TestCase;
use RuntimeException;

/**
 * @covers \Mata\Services\Alarm\Delivery\AlarmTemplateDataFactory
 * @covers \Mata\Services\Alarm\Delivery\AlarmWebhookPayloadFactory
 *
 * @internal
 */
final class AlarmWebhookPayloadFactoryTest extends TestCase
{
    public function testTelegramPayloadIsCompactPlainText(): void
    {
        $payload = $this->factory()->create($this->view(), $this->channel('telegram', ['chat_id' => -1001234567890]));

        $this->assertSame(-1001234567890, $payload['chat_id']);
        $this->assertSame(['is_disabled' => true], $payload['link_preview_options']);
        $this->assertArrayNotHasKey('parse_mode', $payload);
        $this->assertSame(<<<'TEXT'
            🔴 CRITICAL · shop on osira
            Remaining diskspace too low

            Only 2.1 GB left on /var

            prod · alarm #1842 · 2026-10-10 14:03:00 UTC
            https://mata.example.com
            TEXT, $payload['text']);
    }

    public function testMessagesWithJsonAndMarkupCharactersStayVerbatim(): void
    {
        $message = "Quote \" backslash \\ tag <b>&amp;</b>\nsecond line";
        $alarm = new Alarm('fatal_errors_occurring', $message, null, AlarmLevel::Error, 'osira', 'shop');

        $payload = $this->factory()->create($this->view($alarm), $this->channel('telegram', ['chat_id' => '42']));

        $this->assertIsString($payload['text']);
        $this->assertStringContainsString($message, $payload['text']);
        $this->assertStringNotContainsString('&quot;', $payload['text']);
    }

    public function testTelegramTruncatesLongMessagesBelowTheApiLimit(): void
    {
        $alarm = new Alarm('fatal_errors_occurring', str_repeat('x', 10000), null, AlarmLevel::Error, 'osira');

        $payload = $this->factory()->create($this->view($alarm), $this->channel('telegram', ['chat_id' => 1]));

        $this->assertIsString($payload['text']);
        $this->assertLessThan(4096, mb_strlen($payload['text']));
    }

    public function testServerOnlyAlarmsUseTheServerAsHeading(): void
    {
        $alarm = new Alarm('node_unreachable', 'No response', null, AlarmLevel::Critical, 'osira');

        $payload = $this->factory(dashboardUrl: null)->create($this->view($alarm), $this->channel('telegram', ['chat_id' => 1]));

        $this->assertIsString($payload['text']);
        $this->assertStringStartsWith('🔴 CRITICAL · osira', $payload['text']);
        $this->assertStringEndsWith('2026-10-10 14:03:00 UTC', $payload['text']);
    }

    public function testMissingTemplateParamFailsTheDelivery(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to render webhook template webhooks/telegram.json.twig');

        $this->factory()->create($this->view(), $this->channel('telegram', []));
    }

    public function testGenericPayloadCarriesStructuredFields(): void
    {
        $payload = $this->factory()->create($this->view(), $this->channel('generic', []));

        $this->assertSame([
            'alarm' => [
                'id' => 1842,
                'type' => 'remaining_diskspace_too_low',
                'title' => 'Remaining diskspace too low',
                'message' => 'Only 2.1 GB left on /var',
                'level' => 'critical',
                'created_at' => '2026-10-10 14:03:00 UTC',
                'event_id' => 99812,
            ],
            'target' => ['server' => 'osira', 'app' => 'shop', 'environment' => 'prod'],
            'dashboard_url' => 'https://mata.example.com',
            'source' => 'MATA',
        ], $payload);
    }

    public function testTemplateProducingInvalidJsonIsRejected(): void
    {
        $templates = $this->createTempDirectory();
        mkdir($templates . '/webhooks');
        file_put_contents($templates . '/webhooks/broken.json.twig', '{"text": {{ alarm.message }}}');
        file_put_contents($templates . '/webhooks/list.json.twig', '[1, 2]');

        try {
            $factory = new AlarmWebhookPayloadFactory($templates, new AlarmTemplateDataFactory('MATA', null));
            foreach (['broken' => 'produced invalid JSON', 'list' => 'must produce a JSON object'] as $template => $expected) {
                try {
                    $factory->create($this->view(), $this->channel($template, []));
                    $this->fail("Template {$template} must be rejected.");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString($expected, $exception->getMessage());
                }
            }
        } finally {
            $this->cleanupTempFiles([
                $templates . '/webhooks/broken.json.twig',
                $templates . '/webhooks/list.json.twig',
                $templates . '/webhooks',
                $templates,
            ]);
        }
    }

    private function factory(?string $dashboardUrl = 'https://mata.example.com/'): AlarmWebhookPayloadFactory
    {
        return new AlarmWebhookPayloadFactory(
            dirname(__DIR__, 4) . '/templates',
            new AlarmTemplateDataFactory('MATA', $dashboardUrl, new AlarmPresenter(['osira' => ['env' => 'prod']])),
        );
    }

    /** @param array<string,scalar> $params */
    private function channel(string $template, array $params): WebhookChannel
    {
        return new WebhookChannel('telegram', 'https://api.telegram.org/botX/sendMessage', $template, $params, 5);
    }

    private function view(?Alarm $alarm = null): AlarmDeliveryView
    {
        $createdAt = new DateTimeImmutable('2026-10-10 14:03:00 UTC');

        return new AlarmDeliveryView(
            alarm: $alarm ?? new Alarm('remaining_diskspace_too_low', 'Only 2.1 GB left on /var', null, AlarmLevel::Critical, 'osira', 'shop'),
            alarmId: 1842,
            alarmCreatedAt: $createdAt,
            eventId: 99812,
            eventOccurredAt: $createdAt,
        );
    }
}
