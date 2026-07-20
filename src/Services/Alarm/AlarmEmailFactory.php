<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use DateTimeImmutable;
use Mata\Config\Dto\MailConfig;
use Mata\Services\Alarm\Dto\AlarmEmailView;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use Twig\Environment;

/** Creates complete email messages for queued alarm deliveries. */
final class AlarmEmailFactory
{
    public function __construct(
        private Environment $twig,
        private MailConfig $mailConfig,
        private string $applicationName,
        private AlarmPresenter $presenter = new AlarmPresenter(),
    ) {}

    /** @throws RuntimeException */
    public function create(AlarmEmailView $view): Email
    {
        $recipients = array_values(array_filter(
            $this->mailConfig->alarmRecipients,
            static fn (string $recipient): bool => trim($recipient) !== '',
        ));
        if ($recipients === []) {
            throw new RuntimeException('Alarm email recipients not configured.');
        }

        $fromEmail = trim((string) $this->mailConfig->fromEmail);
        if ($fromEmail === '') {
            throw new RuntimeException('Alarm email sender address not configured.');
        }

        $fromName = trim((string) $this->mailConfig->fromName);
        if ($fromName === '') {
            $fromName = $this->applicationName . ' Dashboard';
        }

        $dashboardUrl = $this->normalizeDashboardUrl($this->mailConfig->dashboardUrl);
        $data = $this->createTemplateData($view, $dashboardUrl);

        try {
            $html = $this->twig->render('emails/alarm.html.twig', $data);
            $text = $this->twig->render('emails/alarm.txt.twig', $data);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to render alarm email templates.', 0, $exception);
        }

        $recipientAddresses = array_map(
            static fn (string $recipient): Address => new Address($recipient),
            $recipients,
        );

        return (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to(...$recipientAddresses)
            ->subject($this->createSubject($view))
            ->html($html, $this->mailConfig->charset)
            ->text($text, $this->mailConfig->charset);
    }

    /** @return array<string,mixed> */
    private function createTemplateData(AlarmEmailView $view, ?string $dashboardUrl): array
    {
        $alarm = $view->alarm;
        $app = $this->displayLabel($alarm->app, '');
        $server = $this->displayLabel($alarm->server, 'Global');
        $presentation = $this->presenter->present($alarm, $server, $view->appEnvironment);

        return [
            'branding' => $this->applicationName,
            'alarm' => [
                'id' => $view->alarmId,
                'type' => $alarm->type,
                'message' => $alarm->message,
                'context' => $this->formatContext($alarm->context),
                'created_at' => $view->alarmCreatedAt->format('Y-m-d H:i:s T'),
                'event_id' => $view->eventId,
                'event_occurred_at' => $view->eventOccurredAt->format('Y-m-d H:i:s T'),
                'last_seen_at' => $view->previousAlarmCreatedAt?->format('Y-m-d H:i:s T'),
                'last_seen_ago' => $this->elapsedTime($view->previousAlarmCreatedAt, $view->alarmCreatedAt),
            ],
            'target' => [
                'server' => $server,
                'app' => $app === '' ? 'All applications' : $app,
                'heading' => $app === '' ? "Server: {$server}" : "{$app} on {$server}",
                'is_server_only' => $app === '',
                'color' => $presentation->serverColor,
                'environment' => $presentation->environment,
            ],
            'presentation' => $presentation,
            'severity' => $alarm->level->getPresentation(),
            'dashboard_url' => $dashboardUrl,
        ];
    }

    private function formatContext(?string $context): string
    {
        $context = trim((string) $context);
        if ($context === '') {
            return 'none_provided';
        }

        $decoded = json_decode($context, true);
        if (!is_array($decoded)) {
            return $context;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $context;
    }

    private function elapsedTime(?DateTimeImmutable $lastSeenAt, DateTimeImmutable $currentAt): ?string
    {
        if ($lastSeenAt === null) {
            return null;
        }

        $elapsedSeconds = max(0, $currentAt->getTimestamp() - $lastSeenAt->getTimestamp());
        $elapsedMinutes = intdiv($elapsedSeconds, 60);

        return $elapsedMinutes === 0 ? 'less than a minute ago' : $elapsedMinutes . ' min ago';
    }

    private function createSubject(AlarmEmailView $view): string
    {
        $alarm = $view->alarm;
        $app = $this->subjectLabel($alarm->app, '*');
        $server = $this->subjectLabel($alarm->server, '*');

        return sprintf('%s [%s.%s] %s', $alarm->level->getEmoji(), $app, $server, $this->humanizeType($alarm->type));
    }

    private function humanizeType(?string $value): string
    {
        $type = strtolower($this->subjectLabel($value, ''));
        $type = str_replace('_', ' ', $type);

        return $type === '' ? 'Alarm' : ucfirst($type);
    }

    private function displayLabel(?string $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value === '' ? $fallback : $value;
    }

    private function subjectLabel(?string $value, string $fallback): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? $fallback : $value;
    }

    private function normalizeDashboardUrl(?string $dashboardUrl): ?string
    {
        $dashboardUrl = trim((string) $dashboardUrl);
        if ($dashboardUrl === '') {
            return null;
        }

        $parts = parse_url($dashboardUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if (
            !is_array($parts)
            || !filter_var($dashboardUrl, FILTER_VALIDATE_URL)
            || !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new RuntimeException('Alarm email dashboard URL must be an absolute http or https URL without userinfo.');
        }

        return str_ends_with($dashboardUrl, '/') ? substr($dashboardUrl, 0, -1) : $dashboardUrl;
    }
}
