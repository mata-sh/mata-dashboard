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

    /**
     * MAIL_DASHBOARD_URL for links in mails and reports, without a trailing slash.
     * Null when unset or not an absolute http(s) URL without userinfo; `config validate` warns about the latter.
     */
    public function dashboardLink(): ?string
    {
        $url = $this->dashboardUrl;
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (
            !is_array($parts)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return rtrim($url, '/');
    }
}
