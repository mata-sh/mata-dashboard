<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Services\Alarm\Dto\Alarm;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Channel-specific email sender for alarm delivery dispatch.
 */
final class AlarmEmailSender
{
    private MailerInterface $mailer;
    private LoggerInterface $logger;

    /** @var array<string,mixed> */
    private array $mailConfig;

    /**
     * @param array<string,mixed> $mailConfig
     */
    public function __construct(
        MailerInterface $mailer,
        array $mailConfig,
        ?LoggerInterface $logger = null,
    ) {
        $this->mailer = $mailer;
        $this->mailConfig = $mailConfig;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws RuntimeException when configuration is incomplete
     */
    public function send(Alarm $alarm): void
    {
        $this->logger->debug('Preparing alarm email.', [
            'type' => $alarm->type,
            'level' => $alarm->level->value,
            'server' => $alarm->server,
            'app' => $alarm->app,
            'message' => $alarm->message,
            'context' => $alarm->context,
        ]);

        $recipients = $this->mailConfig['recipients'] ?? [];
        if (!is_array($recipients) || $recipients === []) {
            $this->logger->warning('Alarm email recipients not configured.', [
                'recipients' => $recipients,
            ]);

            throw new RuntimeException('Alarm email recipients not configured.');
        }

        $fromEmail = (string) ($this->mailConfig['from_email'] ?? '');
        $fromName = (string) ($this->mailConfig['from_name'] ?? 'Monitoring Dashboard');
        if ($fromEmail === '') {
            $this->logger->warning('Alarm email sender address not configured.', [
                'from_email' => $fromEmail,
                'from_name' => $fromName,
            ]);

            throw new RuntimeException('Alarm email sender address not configured.');
        }

        $serverLabel = $alarm->server ?? '';
        $appLabel = $alarm->app ?? '';

        // 🔴 shop.points [fatal_errors_occuring]
        $subject = sprintf(
            '%s %s.%s | [%s]',
            $alarm->level->getEmoji(),
            $appLabel !== '' ? $appLabel : '*',
            $serverLabel !== '' ? $serverLabel : '*',
            strtolower($alarm->type),
        );

        $context = $alarm->context ?? '';
        $content = <<<HTML
            <strong>type</strong>: {$alarm->type}<br>
            <strong>level</strong>: {$alarm->level->value}<br>
            <strong>server</strong>: {$serverLabel}<br>
            <strong>app</strong>: {$appLabel}<br>
            <strong>message</strong>:<br>{$alarm->message}<br><br>
            <strong>context</strong>:<br>{$context}<br><br>
        HTML;

        $email = (new Email())
            ->from("{$fromName} <{$fromEmail}>")
            ->to(...$recipients)
            ->subject($subject)
            ->html($content)
        ;
        $this->logger->debug('Sending alarm email.', [
            'subject' => $subject,
            'recipients' => $recipients,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ]);
        $this->mailer->send($email);
    }
}
