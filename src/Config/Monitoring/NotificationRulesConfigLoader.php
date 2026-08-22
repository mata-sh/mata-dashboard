<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Services\Event\EventRecorderService;
use RuntimeException;
use stdClass;

final class NotificationRulesConfigLoader extends AbstractJsonConfigLoader implements MonitoringConfigLoaderInterface
{
    private const SUPPORTED_CHANNELS = ['email'];

    public function __construct(
        private EventRecorderService $eventRecorder,
        private bool $fallbackOnFailure = true,
    ) {}

    public function key(): string
    {
        return 'notification_rules';
    }

    public function load(string $configRoot): array
    {
        return $this->loadNotificationRulesConfig(rtrim($configRoot, '/') . '/notification_rules.json');
    }

    /**
     * @return array{
     *     defaults:array{enabled:bool,channels:list<string>},
     *     event_types:array<string,array{enabled?:bool,channels?:list<string>}>,
     *     application_overrides:array<string,array<string,array{enabled?:bool,channels?:list<string>}>>
     * }
     */
    public function loadNotificationRulesConfig(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new MonitoringConfigLoadException("notification_rules.json file not found at {$filePath}");
        }

        try {
            return $this->validate($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->eventRecorder->recordEvent(
                type: 'notification_rules_config_invalid',
                message: "Could not load {$filePath}; falling back to defaults: {$exception->getMessage()}",
                level: 'warning',
            );

            throw new MonitoringConfigLoadException('notification rules invalid', previous: $exception);
        }
    }

    /**
     * @return array{
     *     defaults:array{enabled:bool,channels:list<string>},
     *     event_types:array<string,array{enabled?:bool,channels?:list<string>}>,
     *     application_overrides:array<string,array<string,array{enabled?:bool,channels?:list<string>}>>
     * }
     */
    public function getDefaultConfig(): array
    {
        return $this->validate(new stdClass());
    }

    public function shouldFallback(): bool
    {
        return $this->fallbackOnFailure;
    }

    /** @return array{defaults:array{enabled:bool,channels:list<string>},event_types:array<string,array{enabled?:bool,channels?:list<string>}>,application_overrides:array<string,array<string,array{enabled?:bool,channels?:list<string>}>>} */
    private function validate(stdClass $rules): array
    {
        $data = get_object_vars($rules);
        $this->rejectUnknownKeys($data, ['defaults', 'event_types', 'application_overrides'], 'notification rules');

        $defaults = ['enabled' => true, 'channels' => ['email']];
        if (array_key_exists('defaults', $data)) {
            $defaults = array_replace($defaults, $this->validateRule($data['defaults'], 'defaults'));
        }

        return [
            'defaults' => $defaults,
            'event_types' => $this->validateEventTypes(
                array_key_exists('event_types', $data) ? $data['event_types'] : new stdClass(),
                'event_types',
            ),
            'application_overrides' => $this->validateApplicationOverrides(
                array_key_exists('application_overrides', $data) ? $data['application_overrides'] : new stdClass(),
            ),
        ];
    }

    /** @return array<string,array{enabled?:bool,channels?:list<string>}> */
    private function validateEventTypes(mixed $eventTypes, string $path): array
    {
        if (!$eventTypes instanceof stdClass) {
            throw new RuntimeException("{$path} must be a JSON object.");
        }

        $validated = [];
        foreach (get_object_vars($eventTypes) as $eventType => $rule) {
            $this->assertNonEmptyName((string) $eventType, "{$path} key");
            $validated[(string) $eventType] = $this->validateRule($rule, "{$path}.{$eventType}");
        }

        return $validated;
    }

    /** @return array<string,array<string,array{enabled?:bool,channels?:list<string>}>> */
    private function validateApplicationOverrides(mixed $applicationOverrides): array
    {
        if (!$applicationOverrides instanceof stdClass) {
            throw new RuntimeException('application_overrides must be a JSON object.');
        }

        $validated = [];
        foreach (get_object_vars($applicationOverrides) as $appCode => $eventTypes) {
            $this->assertNonEmptyName((string) $appCode, 'application_overrides key');
            $validated[(string) $appCode] = $this->validateEventTypes($eventTypes, "application_overrides.{$appCode}");
        }

        return $validated;
    }

    /** @return array{enabled?:bool,channels?:list<string>} */
    private function validateRule(mixed $rule, string $path): array
    {
        if (!$rule instanceof stdClass) {
            throw new RuntimeException("{$path} must be a JSON object.");
        }

        $data = get_object_vars($rule);
        $this->rejectUnknownKeys($data, ['enabled', 'channels'], $path);
        $validated = [];
        if (array_key_exists('enabled', $data)) {
            if (!is_bool($data['enabled'])) {
                throw new RuntimeException("{$path}.enabled must be a boolean.");
            }
            $validated['enabled'] = $data['enabled'];
        }
        if (array_key_exists('channels', $data)) {
            $validated['channels'] = $this->validateChannels($data['channels'], "{$path}.channels");
        }

        return $validated;
    }

    /** @return list<string> */
    private function validateChannels(mixed $channels, string $path): array
    {
        if (!is_array($channels) || !array_is_list($channels)) {
            throw new RuntimeException("{$path} must be a JSON list.");
        }

        $validated = [];
        foreach ($channels as $channel) {
            if (!is_string($channel) || trim($channel) === '') {
                throw new RuntimeException("{$path} values must be non-empty strings.");
            }
            $channel = strtolower(trim($channel));
            if (!in_array($channel, self::SUPPORTED_CHANNELS, true)) {
                throw new RuntimeException("{$path} contains unsupported channel {$channel}.");
            }
            $validated[] = $channel;
        }

        return array_values(array_unique($validated));
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string>        $allowed
     */
    private function rejectUnknownKeys(array $data, array $allowed, string $path): void
    {
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw new RuntimeException("{$path} contains unknown key {$unknown[0]}.");
        }
    }

    private function assertNonEmptyName(string $name, string $path): void
    {
        if (trim($name) === '') {
            throw new RuntimeException("{$path} must not be empty.");
        }
    }
}
