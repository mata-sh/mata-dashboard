<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing mail and notification configuration.
 *
 * Contains SMTP settings, sender information, and recipient lists
 * for the application's email notification system.
 */
final class MailConfig
{
    /**
     * @param array<int,string> $alarmRecipients List of email addresses for alarm notifications
     * @param array<int,string> $testRecipients  List of email addresses for test emails
     * @param array<int,string> $devRecipients   List of email addresses for development notifications
     */
    public function __construct(
        public readonly ?string $dsn,
        public readonly ?string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $alarmRecipients,
        public readonly array $testRecipients,
        public readonly array $devRecipients,
        public readonly string $charset,
        public readonly ?string $dashboardUrl,
    ) {}
}
