<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery;

use DateTimeImmutable;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use RuntimeException;

/** Builds the template data shared by every alarm channel, so all channels render identical fields. */
final readonly class AlarmTemplateDataFactory
{
    private AlarmPresenter $presenter;

    /** @param array<string,mixed> $servers servers.json, for each server's color and environment */
    public function __construct(
        public string $applicationName,
        private ?string $dashboardUrl,
        array $servers = [],
    ) {
        $this->presenter = new AlarmPresenter($servers);
    }

    /**
     * @return array<string,mixed>
     *
     * @throws RuntimeException when the configured dashboard URL is invalid
     */
    public function create(AlarmDeliveryView $view): array
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
                'title' => $this->title($alarm->type),
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
            'presentation' => ['description' => $presentation->description, 'metric' => $presentation->metric],
            'severity' => $alarm->level->getPresentation() + ['emoji' => $alarm->level->getEmoji()],
            'dashboard_url' => $this->normalizeDashboardUrl($this->dashboardUrl),
        ];
    }

    /** Humanize an event type for headlines, e.g. `node_unhealthy` becomes `Node unhealthy`. */
    public function title(?string $type): string
    {
        $type = strtolower($this->singleLine($type, ''));
        $type = str_replace('_', ' ', $type);

        return $type === '' ? 'Alarm' : ucfirst($type);
    }

    /** Collapse control characters and whitespace runs for single-line fields such as subjects. */
    public function singleLine(?string $value, string $fallback): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? $fallback : $value;
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

    private function displayLabel(?string $value, string $fallback): string
    {
        $value = trim((string) $value);

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
