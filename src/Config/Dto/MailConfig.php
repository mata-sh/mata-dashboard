<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;

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
     */
    public function __construct(
        public readonly ?string $dsn,
        public readonly ?string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $alarmRecipients,
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

    /**
     * SYM_FROM_EMAIL named SYM_FROM_NAME, or "<application> Dashboard" when the name is unset.
     *
     * @throws RuntimeException when SYM_FROM_EMAIL is unset or invalid
     */
    public function sender(string $applicationName): Address
    {
        $email = trim((string) $this->fromEmail);
        if ($email === '') {
            throw new RuntimeException('Mail sender address not configured (SYM_FROM_EMAIL).');
        }
        $name = trim((string) $this->fromName);

        return self::address($email, $name !== '' ? $name : $applicationName . ' Dashboard');
    }

    /**
     * The first list that has a non-blank address, trimmed; MAIL_ALARM_RECIPIENTS when none has.
     *
     * @param list<string> ...$preferred
     *
     * @return list<string> empty when MAIL_ALARM_RECIPIENTS is empty too
     */
    public function recipients(array ...$preferred): array
    {
        foreach ([...$preferred, $this->alarmRecipients] as $recipients) {
            $recipients = array_values(array_filter(array_map('trim', $recipients), static fn (string $recipient): bool => $recipient !== ''));
            if ($recipients !== []) {
                return $recipients;
            }
        }

        return [];
    }

    /**
     * @param list<string> $emails
     *
     * @return list<Address>
     *
     * @throws RuntimeException when an address is invalid
     */
    public static function addresses(array $emails): array
    {
        return array_map(self::address(...), $emails);
    }

    /** @throws RuntimeException when the address is invalid */
    public static function address(string $email, string $name = ''): Address
    {
        try {
            return new Address($email, $name);
        } catch (RfcComplianceException $exception) {
            throw new RuntimeException(sprintf('Invalid email address "%s".', $email), previous: $exception);
        }
    }
}
