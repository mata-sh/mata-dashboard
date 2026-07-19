<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use DateTimeImmutable;
use Mata\Config\Dto\MailConfig;
use Mata\Services\Alarm\AlarmEmailFactory;
use Mata\Services\Alarm\AlarmPresenter;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmEmailView;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Tests\TestCase;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @covers \Mata\Services\Alarm\AlarmEmailFactory
 *
 * @internal
 */
final class AlarmEmailFactoryTest extends TestCase
{
    public function testItBuildsTheSubjectWithBracketedTargetAndHumanizedType(): void
    {
        $email = $this->factory()->create($this->view());

        $this->assertSame('🔴 [Shop.Api-1] Fatal errors occurring', $email->getSubject());
    }

    public function testItUsesAsterisksForMissingSubjectLabels(): void
    {
        $view = $this->view(new Alarm(server: null, app: null, type: 'DISK_FULL', message: 'Disk full'));

        $this->assertSame('🔵 [*.*] Disk full', $this->factory()->create($view)->getSubject());
    }

    public function testItSetsSenderRecipientsAndConfiguredCharsets(): void
    {
        $email = $this->factory()->create($this->view());

        $this->assertSame('alerts@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('MATA Alerts', $email->getFrom()[0]->getName());
        $this->assertSame(['ops@example.com', 'oncall@example.com'], array_map(
            static fn ($address): string => $address->getAddress(),
            $email->getTo(),
        ));
        $this->assertSame('UTF-8', $email->getHtmlCharset());
        $this->assertSame('UTF-8', $email->getTextCharset());
    }

    public function testItRendersCompleteHtmlAndPlainTextBodies(): void
    {
        $email = $this->factory()->create($this->view());
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();

        $this->assertStringContainsString('ALARM #123', $html);
        $this->assertStringContainsString('2026-07-11 17:20:00 UTC', $html);
        $this->assertStringContainsString('Critical', $html);
        $this->assertStringContainsString('FATAL_ERRORS_OCCURRING', $html);
        $this->assertStringContainsString('>2k</div>', $html);
        $this->assertStringContainsString('Shop on Api-1', $html);
        $this->assertStringContainsString('Detected fatal errors above threshold.', $html);
        $this->assertStringContainsString('connection refused', $html);
        $this->assertStringContainsString('#ff3b4e', $html);
        $this->assertStringContainsString('CRITICAL · Api-1 · 2k fatal_errors_occurring · Alarm #123', $html);
        $this->assertStringContainsString('transmission by MATA monitoring', $html);
        $this->assertStringContainsString('[CRITICAL] Shop on Api-1', $text);
        $this->assertStringContainsString('Alarm ID: 123', $text);
        $this->assertStringContainsString('Current alarm: 2026-07-11 17:20:00 UTC', $text);
        $this->assertStringContainsString('Context:', $text);
        $this->assertStringNotContainsString('<table', $text);
    }

    public function testItRendersThePreviousMatchingAlarmAsLastSeen(): void
    {
        $view = new AlarmEmailView(
            new Alarm('node_unhealthy', 'Unhealthy health response', null, AlarmLevel::Warning, 'Api-1'),
            123,
            new DateTimeImmutable('2026-07-11 17:20:00 UTC'),
            new DateTimeImmutable('2026-07-10 16:19:00 UTC'),
        );
        $email = $this->factory()->create($view);

        $this->assertStringContainsString('Server: Api-1', (string) $email->getHtmlBody());
        $this->assertStringContainsString('2026-07-10 16:19:00 UTC', (string) $email->getHtmlBody());
        $this->assertStringContainsString('Last seen: 2026-07-10 16:19:00 UTC', (string) $email->getTextBody());
    }

    public function testItRendersServerColorAndEnvironmentBadge(): void
    {
        $presenter = new AlarmPresenter(servers: [
            'Api-1' => ['primary_color' => '#E88C02', 'env' => 'test'],
        ]);
        $html = (string) $this->factory(presenter: $presenter)->create($this->view())->getHtmlBody();

        $this->assertStringContainsString('border-bottom:4px solid #E88C02', $html);
        $this->assertStringContainsString('background-color:#f59e0b', $html);
        $this->assertStringContainsString('>TEST</span>', $html);
    }

    public function testItUsesApplicationNameBrandingInTheHtmlFooter(): void
    {
        $html = (string) $this->factory(applicationName: 'Acme Watch')->create($this->view())->getHtmlBody();

        $this->assertStringContainsString('transmission by Acme Watch monitoring', $html);
    }

    public function testItEscapesHtmlPayloadsAndPreservesLineBreaks(): void
    {
        $payload = "first line\n<img src=x onerror=alert(1)>";
        $view = $this->view(new Alarm('type', $payload, $payload, AlarmLevel::Warning, 'server', 'app'));
        $email = $this->factory()->create($view);
        $html = (string) $email->getHtmlBody();

        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('first line' . "\n" . '&lt;img', $html);
        $this->assertStringContainsString('white-space:pre-wrap', $html);
    }

    public function testItNormalizesControlCharactersInSubjectFields(): void
    {
        $view = $this->view(new Alarm("TYPE\r\nInjected", 'Message', null, AlarmLevel::Info, "server\r\nB", "app\nA"));
        $subject = $this->factory()->create($view)->getSubject();

        $this->assertSame('🔵 [app A.server B] Type injected', $subject);
        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
    }

    public function testItRendersFallbackContentForEmptyFields(): void
    {
        $view = $this->view(new Alarm('type', 'Message', '', AlarmLevel::Info, '', ''));
        $html = (string) $this->factory()->create($view)->getHtmlBody();

        $this->assertStringContainsString('>Global</span>', $html);
        $this->assertStringContainsString('>All applications</span>', $html);
        $this->assertStringContainsString('none_provided', $html);
    }

    public function testItRendersDashboardCtaForAValidUrl(): void
    {
        $email = $this->factory('https://mata.example.com/')->create($this->view());

        $this->assertStringContainsString('href="https://mata.example.com"', (string) $email->getHtmlBody());
        $this->assertStringContainsString('Open dashboard: https://mata.example.com', (string) $email->getTextBody());
    }

    public function testItOmitsDashboardCtaWhenUnset(): void
    {
        $email = $this->factory()->create($this->view());

        $this->assertStringNotContainsString('Open dashboard', (string) $email->getHtmlBody());
        $this->assertStringNotContainsString('Open dashboard', (string) $email->getTextBody());
    }

    public function testItRejectsInvalidDashboardUrlsDuringCreation(): void
    {
        $factory = $this->factory('https://user:pass@mata.example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dashboard URL');
        $factory->create($this->view());
    }

    public function testItRejectsMissingRecipients(): void
    {
        $factory = $this->factory(alarmRecipients: []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('recipients not configured');
        $factory->create($this->view());
    }

    public function testItRejectsMissingSenderAddress(): void
    {
        $factory = $this->factory(fromEmail: null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sender address not configured');
        $factory->create($this->view());
    }

    public function testItUsesTheApplicationNameWhenSenderNameIsMissing(): void
    {
        $email = $this->factory(fromName: null)->create($this->view());

        $this->assertSame('MATA Dashboard', $email->getFrom()[0]->getName());
    }

    public function testItRendersEveryAlarmLevel(): void
    {
        foreach (AlarmLevel::cases() as $level) {
            $email = $this->factory()->create($this->view(new Alarm('type', 'Message', null, $level, 'server', 'app')));

            $this->assertStringContainsString(ucfirst($level->value), (string) $email->getHtmlBody());
        }
    }

    private function factory(
        ?string $dashboardUrl = null,
        ?string $fromEmail = 'alerts@example.com',
        ?string $fromName = 'MATA Alerts',
        array $alarmRecipients = ['ops@example.com', 'oncall@example.com'],
        string $applicationName = 'MATA',
        ?AlarmPresenter $presenter = null,
    ): AlarmEmailFactory {
        return new AlarmEmailFactory(
            new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates'), ['autoescape' => 'name']),
            new MailConfig(null, $fromEmail, $fromName, $alarmRecipients, [], [], 'mailer', 'mailer', 'UTF-8', 78, $dashboardUrl),
            $applicationName,
            $presenter ?? new AlarmPresenter(),
        );
    }

    private function view(?Alarm $alarm = null): AlarmEmailView
    {
        return new AlarmEmailView(
            $alarm ?? new Alarm('fatal_errors_occurring', 'Fatal Error Detected (Count: 2k): Shop on Server: Api-1', 'connection refused', AlarmLevel::Critical, 'Api-1', 'Shop'),
            123,
            new DateTimeImmutable('2026-07-11 17:20:00 UTC'),
        );
    }
}
