<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigValueValidator;

/** Notification routing rules, cascading defaults to event type to application override. */
final readonly class NotificationRulesConfig
{
    /**
     * Rules accept any well-formed channel name. Whether a channel is configured is checked
     * per alarm run, so a missing webhook channel drops only its own deliveries.
     */
    public const CHANNEL_NAME_MAX_LENGTH = 50;

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
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        self::rejectUnknownKeys($data, ['defaults', 'event_types', 'application_overrides'], 'notification rules');

        // `+=` fills only absent keys, so an explicit null still fails validation.
        $data += ['defaults' => [], 'event_types' => [], 'application_overrides' => []];

        return new self(
            defaults: array_replace(self::DEFAULT_RULE, self::validateRule($data['defaults'], 'defaults')),
            eventTypes: self::validateRules($data['event_types'], 'event_types'),
            applicationOverrides: self::validateApplicationOverrides($data['application_overrides']),
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

    /** @return array<string,array{enabled?:bool,channels?:list<string>}> event type => rule */
    private static function validateRules(mixed $rules, string $path): array
    {
        self::assertJsonObject($rules, $path);

        $validated = [];
        foreach ($rules as $eventType => $rule) {
            self::assertNonEmptyKey($eventType, $path);
            $validated[(string) $eventType] = self::validateRule($rule, "{$path}.{$eventType}");
        }

        return $validated;
    }

    /** @return array<string,array<string,array{enabled?:bool,channels?:list<string>}>> application => rules */
    private static function validateApplicationOverrides(mixed $overrides): array
    {
        self::assertJsonObject($overrides, 'application_overrides');

        $validated = [];
        foreach ($overrides as $application => $rules) {
            self::assertNonEmptyKey($application, 'application_overrides');
            $validated[(string) $application] = self::validateRules($rules, "application_overrides.{$application}");
        }

        return $validated;
    }

    /** @return array{enabled?:bool,channels?:list<string>} */
    private static function validateRule(mixed $rule, string $path): array
    {
        self::assertJsonObject($rule, $path);
        self::rejectUnknownKeys($rule, ['enabled', 'channels'], $path);
        $validated = [];
        if (array_key_exists('enabled', $rule)) {
            if (!is_bool($rule['enabled'])) {
                throw new InvalidArgumentException("{$path}.enabled must be a boolean.");
            }
            $validated['enabled'] = $rule['enabled'];
        }
        if (array_key_exists('channels', $rule)) {
            $validated['channels'] = self::validateChannels($rule['channels'], "{$path}.channels");
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
            if (!ConfigValueValidator::isSlug($channel, self::CHANNEL_NAME_MAX_LENGTH)) {
                throw new InvalidArgumentException("{$path} contains invalid channel name {$channel}.");
            }
            $validated[] = $channel;
        }

        return array_values(array_unique($validated));
    }

    /**
     * A decoded JSON object; an empty one decodes to the same [] as an empty list.
     *
     * @phpstan-assert array<array-key, mixed> $value
     */
    private static function assertJsonObject(mixed $value, string $path): void
    {
        if (!ConfigValueValidator::isObject($value)) {
            throw new InvalidArgumentException("{$path} must be a JSON object.");
        }
    }

    private static function assertNonEmptyKey(int|string $key, string $path): void
    {
        if (trim((string) $key) === '') {
            throw new InvalidArgumentException("{$path} key must not be empty.");
        }
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
}
