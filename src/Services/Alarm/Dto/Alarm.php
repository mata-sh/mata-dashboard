<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

/**
 * Alarm notification DTO.
 *
 * Replaces 7-parameter method signatures with a single, type-safe object.
 */
final readonly class Alarm
{
    /**
     * @param string        $type     Event/alarm type identifier
     * @param string        $message  Human-readable alarm message
     * @param null|string   $context  Additional context payload (HTML/text)
     * @param AlarmLevel    $level    Severity level
     * @param null|string   $server   Server context (optional)
     * @param null|string   $app      Application context (optional)
     * @param array<string> $channels Delivery channels (email, slack, etc.)
     */
    public function __construct(
        public string $type,
        public string $message,
        public ?string $context = null,
        public AlarmLevel $level = AlarmLevel::Info,
        public ?string $server = null,
        public ?string $app = null,
        public array $channels = ['email']
    ) {}

}
