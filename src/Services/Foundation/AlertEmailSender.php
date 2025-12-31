<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Watcher\Services\Foundation\Dto\Alert;

/**
 * Channel-specific email sender for alert dispatch.
 */
final class AlertEmailSender
{
    private MailerInterface $mailer;

    /** @var array<string,mixed> */
    private array $mailConfig;

    /**
     * @param array<string,mixed> $mailConfig
     */
    public function __construct(MailerInterface $mailer, array $mailConfig)
    {
        $this->mailer = $mailer;
        $this->mailConfig = $mailConfig;
    }

    /**
     * @throws RuntimeException when configuration is incomplete
     */
    public function send(Alert $alert): void
    {
        $recipients = $this->mailConfig['recipients'] ?? [];
        if (!is_array($recipients) || $recipients === []) {
            throw new RuntimeException('Alert email recipients not configured.');
        }

        $fromEmail = (string) ($this->mailConfig['from_email'] ?? '');
        $fromName = (string) ($this->mailConfig['from_name'] ?? 'Monitoring Dashboard');
        if ($fromEmail === '') {
            throw new RuntimeException('Alert email sender address not configured.');
        }

        $serverLabel = $alert->server ?? '';
        $appLabel = $alert->app ?? '';

        $subject = sprintf(
            '%s %s | [%s] on [%s]',
            $alert->level->getEmoji(),
            strtoupper($alert->type),
            $appLabel !== '' ? $appLabel : '-',
            $serverLabel !== '' ? $serverLabel : '-'
        );

        $context = $alert->context ?? '';
        $content = <<<HTML
            <strong>type</strong>: {$alert->type}<br>
            <strong>level</strong>: {$alert->level->value}<br>
            <strong>server</strong>: {$serverLabel}<br>
            <strong>app</strong>: {$appLabel}<br>
            <strong>message</strong>:<br>{$alert->message}<br><br>
            <strong>context</strong>:<br>{$context}<br><br>
        HTML;

        $email = (new Email())
            ->from("{$fromName} <{$fromEmail}>")
            ->to(...$recipients)
            ->subject($subject)
            ->html($content)
        ;

        $this->mailer->send($email);
    }
}
