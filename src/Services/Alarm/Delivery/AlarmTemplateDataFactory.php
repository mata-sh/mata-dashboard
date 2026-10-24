<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery;

use DateTimeImmutable;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;

/** Builds the template data shared by every alarm channel, so all channels render identical fields. */
final readonly class AlarmTemplateDataFactory
{
    /** Advisory alarms carry no server or application; the source names them instead. */
    public const ADVISORY_TYPE = 'advisories.matched';

    private AlarmPresenter $presenter;

    /**
     * @param ?string             $dashboardLink see MailConfig::dashboardLink()
     * @param array<string,mixed> $servers       servers.json, for each server's color and environment
     */
    public function __construct(
        public string $applicationName,
        private ?string $dashboardLink,
        array $servers = [],
    ) {
        $this->presenter = new AlarmPresenter($servers);
    }

    /** @return array<string,mixed> */
    public function create(AlarmDeliveryView $view): array
    {
        $alarm = $view->alarm;
        $app = $this->displayLabel($alarm->app, '');
        $server = $this->displayLabel($alarm->server, $this->advisorySource($alarm->type, $alarm->context) ?? 'Global');
        $presentation = $this->presenter->present($alarm, $server, $view->appEnvironment);

        return [
            'branding' => $this->applicationName,
            'alarm' => [
                'id' => $view->alarmId,
                'type' => $alarm->type,
                'title' => $this->title($alarm->type, $alarm->message),
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
            'presentation' => ['description' => $presentation->description, 'metric' => $presentation->metric, 'link' => $presentation->link],
            'severity' => $alarm->level->getPresentation() + ['emoji' => $alarm->level->getEmoji()],
            'dashboard_url' => $this->dashboardLink,
        ];
    }

    /** Advisories have no server; their source takes the server's place in the headline. */
    private function advisorySource(string $type, ?string $context): ?string
    {
        if ($type !== self::ADVISORY_TYPE) {
            return null;
        }
        $decoded = json_decode((string) $context, true);
        $name = is_array($decoded) ? ($decoded['additional_data']['source_name'] ?? null) : null;

        return is_string($name) && trim($name) !== '' ? $name : 'Advisory';
    }

    /** Collapse control characters and whitespace runs for single-line fields such as subjects. */
    public function singleLine(?string $value, string $fallback): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? $fallback : $value;
    }

    /**
     * Humanize an event type for headlines, e.g. `node_unhealthy` becomes `Node unhealthy`.
     * A dotted prefix names the area: `server_metrics.ingestion_run_empty` becomes `Server metrics: ingestion run empty`.
     */
    private function title(?string $type, ?string $message = null): string
    {
        // An advisory's own title says more than its event type.
        if ($type === self::ADVISORY_TYPE && trim((string) $message) !== '') {
            return mb_strimwidth($this->singleLine($message, ''), 0, 160, '…');
        }
        $type = strtolower($this->singleLine($type, ''));
        $type = preg_replace('/\./', ': ', $type, 1) ?? $type;
        $type = str_replace(['.', '_'], ' ', $type);

        return $type === '' ? 'Alarm' : ucfirst($type);
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
}
