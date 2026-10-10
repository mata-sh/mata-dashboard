<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use stdClass;

/** Notification routing rules, cascading defaults to event type to application override. */
final readonly class NotificationRulesConfig
{
    /**
     * Rules accept any well-formed channel name. Whether a channel is configured is checked
     * per alarm run, so a missing webhook channel drops only its own deliveries.
     */
    public const CHANNEL_NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,49}$/';

    /** @var array{enabled:bool,channels:list<string>} */
    public const DEFAULT_RULE = ['enabled' => true, 'channels' => ['email']];

    /**
     * @param array{enabled:bool,channels:list<string>}                               $defaults
     * @param array<string,array{enabled?:bool,channels?:list<string>}>               $eventTypes
     * @param array<string,array<string,array{enabled?:bool,channels?:list<string>}>> $applicationOverrides
     */
    public function __construct(
        public array $defaults,
        public array $eventTypes,
        public array $applicationOverrides,
    ) {
        self::validateNormalizedRule($defaults, 'notification_rules.defaults', requireComplete: true);
        foreach ($eventTypes as $eventType => $rule) {
            self::assertNonEmptyName((string) $eventType, 'notification_rules.event_types key');
            self::validateNormalizedRule($rule, "notification_rules.event_types.{$eventType}");
        }
        foreach ($applicationOverrides as $appCode => $rules) {
            self::assertNonEmptyName((string) $appCode, 'notification_rules.application_overrides key');
            if (!is_array($rules)) {
                throw new InvalidArgumentException("notification_rules.application_overrides.{$appCode} must be an object.");
            }
            foreach ($rules as $eventType => $rule) {
                self::assertNonEmptyName((string) $eventType, "notification_rules.application_overrides.{$appCode} key");
                self::validateNormalizedRule(
                    $rule,
                    "notification_rules.application_overrides.{$appCode}.{$eventType}",
                );
            }
        }
    }

    public static function fromObject(stdClass $rules): self
    {
        $data = get_object_vars($rules);
        self::rejectUnknownKeys($data, ['defaults', 'event_types', 'application_overrides'], 'notification rules');

        $defaults = self::DEFAULT_RULE;
        if (array_key_exists('defaults', $data)) {
            $defaults = array_replace($defaults, self::validateRule($data['defaults'], 'defaults'));
        }

        return new self(
            defaults: $defaults,
            eventTypes: self::validateEventTypes(
                array_key_exists('event_types', $data) ? $data['event_types'] : new stdClass(),
                'event_types',
            ),
            applicationOverrides: self::validateApplicationOverrides(
                array_key_exists('application_overrides', $data) ? $data['application_overrides'] : new stdClass(),
            ),
        );
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_RULE, [], []);
    }

    /**
     * Every channel referenced by defaults, event types, or application overrides.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        $channels = $this->defaults['channels'];
        foreach ($this->eventTypes as $rule) {
            $channels = [...$channels, ...($rule['channels'] ?? [])];
        }
        foreach ($this->applicationOverrides as $rules) {
            foreach ($rules as $rule) {
                $channels = [...$channels, ...($rule['channels'] ?? [])];
            }
        }

        return array_values(array_unique($channels));
    }

    /** @return array<string,array{enabled?:bool,channels?:list<string>}> */
    private static function validateEventTypes(mixed $eventTypes, string $path): array
    {
        if (!$eventTypes instanceof stdClass) {
            throw new InvalidArgumentException("{$path} must be a JSON object.");
        }

        $validated = [];
        foreach (get_object_vars($eventTypes) as $eventType => $rule) {
            self::assertNonEmptyName((string) $eventType, "{$path} key");
            $validated[(string) $eventType] = self::validateRule($rule, "{$path}.{$eventType}");
        }

        return $validated;
    }

    /** @return array<string,array<string,array{enabled?:bool,channels?:list<string>}>> */
    private static function validateApplicationOverrides(mixed $applicationOverrides): array
    {
        if (!$applicationOverrides instanceof stdClass) {
            throw new InvalidArgumentException('application_overrides must be a JSON object.');
        }

        $validated = [];
        foreach (get_object_vars($applicationOverrides) as $appCode => $eventTypes) {
            self::assertNonEmptyName((string) $appCode, 'application_overrides key');
            $validated[(string) $appCode] = self::validateEventTypes($eventTypes, "application_overrides.{$appCode}");
        }

        return $validated;
    }

    /** @return array{enabled?:bool,channels?:list<string>} */
    private static function validateRule(mixed $rule, string $path): array
    {
        if (!$rule instanceof stdClass) {
            throw new InvalidArgumentException("{$path} must be a JSON object.");
        }

        $data = get_object_vars($rule);
        self::rejectUnknownKeys($data, ['enabled', 'channels'], $path);
        $validated = [];
        if (array_key_exists('enabled', $data)) {
            if (!is_bool($data['enabled'])) {
                throw new InvalidArgumentException("{$path}.enabled must be a boolean.");
            }
            $validated['enabled'] = $data['enabled'];
        }
        if (array_key_exists('channels', $data)) {
            $validated['channels'] = self::validateChannels($data['channels'], "{$path}.channels");
        }

        return $validated;
    }

    /** @return list<string> */
    private static function validateChannels(mixed $channels, string $path): array
    {
        if (!is_array($channels) || !array_is_list($channels)) {
            throw new InvalidArgumentException("{$path} must be a JSON list.");
        }

        $validated = [];
        foreach ($channels as $channel) {
            if (!is_string($channel) || trim($channel) === '') {
                throw new InvalidArgumentException("{$path} values must be non-empty strings.");
            }
            $channel = strtolower(trim($channel));
            if (preg_match(self::CHANNEL_NAME_PATTERN, $channel) !== 1) {
                throw new InvalidArgumentException("{$path} contains invalid channel name {$channel}.");
            }
            $validated[] = $channel;
        }

        return array_values(array_unique($validated));
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string>        $allowed
     */
    private static function rejectUnknownKeys(array $data, array $allowed, string $path): void
    {
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$path} contains unknown key {$unknown[0]}.");
        }
    }

    private static function validateNormalizedRule(mixed $rule, string $path, bool $requireComplete = false): void
    {
        if (!is_array($rule)) {
            throw new InvalidArgumentException("{$path} must be an object.");
        }
        self::rejectUnknownKeys($rule, ['enabled', 'channels'], $path);
        if ($requireComplete && (!array_key_exists('enabled', $rule) || !array_key_exists('channels', $rule))) {
            throw new InvalidArgumentException("{$path} must define enabled and channels.");
        }
        if (array_key_exists('enabled', $rule) && !is_bool($rule['enabled'])) {
            throw new InvalidArgumentException("{$path}.enabled must be a boolean.");
        }
        if (!array_key_exists('channels', $rule)) {
            return;
        }
        if (!is_array($rule['channels']) || !array_is_list($rule['channels'])) {
            throw new InvalidArgumentException("{$path}.channels must be a list.");
        }
        foreach ($rule['channels'] as $channel) {
            if (!is_string($channel)
                || trim($channel) !== $channel
                || strtolower($channel) !== $channel
                || preg_match(self::CHANNEL_NAME_PATTERN, $channel) !== 1) {
                throw new InvalidArgumentException("{$path}.channels contains an invalid channel.");
            }
        }
        if (count(array_unique($rule['channels'])) !== count($rule['channels'])) {
            throw new InvalidArgumentException("{$path}.channels must not contain duplicates.");
        }
    }

    private static function assertNonEmptyName(string $name, string $path): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException("{$path} must not be empty.");
        }
    }
}
