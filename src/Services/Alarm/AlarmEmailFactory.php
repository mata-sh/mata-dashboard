<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Config\Dto\MailConfig;
use Mata\Services\Alarm\Dto\AlarmEmailView;
use Mata\Services\Alarm\Dto\AlarmLevel;
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
        $severity = $this->severityPresentation($alarm->level);

        return [
            'branding' => $this->applicationName,
            'alarm' => [
                'id' => $view->alarmId,
                'type' => $alarm->type,
                'message' => $alarm->message,
                'context' => $alarm->context,
                'created_at' => $view->alarmCreatedAt->format('Y-m-d H:i:s T'),
            ],
            'target' => [
                'server' => $this->displayLabel($alarm->server, 'Global'),
                'app' => $this->displayLabel($alarm->app, 'All applications'),
            ],
            'severity' => $severity,
            'dashboard_url' => $dashboardUrl,
        ];
    }

    private function createSubject(AlarmEmailView $view): string
    {
        $alarm = $view->alarm;
        $app = $this->subjectLabel($alarm->app, '*');
        $server = $this->subjectLabel($alarm->server, '*');
        $type = strtolower($this->subjectLabel($alarm->type, ''));

        return sprintf('%s %s.%s | [%s]', $alarm->level->getEmoji(), $app, $server, $type);
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

    /** @return array{value:string,display_label:string,emoji:string,accent:string,background:string} */
    private function severityPresentation(AlarmLevel $level): array
    {
        return match ($level) {
            AlarmLevel::Critical => [
                'value' => 'critical', 'display_label' => 'Critical', 'emoji' => '🔴',
                'accent' => '#dc2626', 'background' => '#fef2f2',
            ],
            AlarmLevel::Error => [
                'value' => 'error', 'display_label' => 'Error', 'emoji' => '🟠',
                'accent' => '#d97706', 'background' => '#fffbeb',
            ],
            AlarmLevel::Warning => [
                'value' => 'warning', 'display_label' => 'Warning', 'emoji' => '🟡',
                'accent' => '#ea580c', 'background' => '#fff7ed',
            ],
            AlarmLevel::Info => [
                'value' => 'info', 'display_label' => 'Info', 'emoji' => '🔵',
                'accent' => '#2563eb', 'background' => '#eff6ff',
            ],
        };
    }
}
