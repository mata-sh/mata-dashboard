<?php

declare(strict_types=1);

namespace Mata\Services\Advisory;

use Mata\Config\Dto\MailConfig;
use Mata\Services\Advisory\Report\AdvisoryReport;
use Mata\Services\Advisory\Report\AdvisoryReportPresenter;
use Mata\Services\Advisory\Report\AdvisoryReportRenderer;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Mails an advisory report: the HTML report as body, the Markdown as text part, and the PDF
 * attached on request. The subject follows the alarm convention with the report's worst
 * severity: "🔴 [ADVISORIES] Security advisory report …".
 */
final class AdvisoryDigestMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private MailConfig $mailConfig,
        private AdvisoryReportRenderer $renderer,
        private string $applicationName,
    ) {}

    /**
     * @param list<string> $recipients empty: MAIL_ALARM_RECIPIENTS
     *
     * @return list<string> the recipients mailed
     *
     * @throws RuntimeException when mail is not configured or the transport fails
     */
    public function send(AdvisoryReport $report, array $recipients, bool $attachPdf): array
    {
        $recipients = array_values(array_filter(
            $recipients !== [] ? $recipients : $this->mailConfig->alarmRecipients,
            static fn (string $recipient): bool => trim($recipient) !== '',
        ));
        if ($recipients === []) {
            throw new RuntimeException('No digest recipients: set advisories.digest.recipients or MAIL_ALARM_RECIPIENTS.');
        }
        $fromEmail = trim((string) $this->mailConfig->fromEmail);
        if ($fromEmail === '') {
            throw new RuntimeException('Mail sender address not configured (MAIL_FROM_EMAIL).');
        }
        $fromName = trim((string) $this->mailConfig->fromName) ?: $this->applicationName . ' Dashboard';

        $email = (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to(...array_map(static fn (string $recipient): Address => new Address($recipient), $recipients))
            ->subject($this->subject($report))
            ->html($this->renderer->html($report), $this->mailConfig->charset)
            ->text($this->renderer->markdown($report), $this->mailConfig->charset);
        if ($attachPdf) {
            $email->attach($this->renderer->pdf($report), AdvisoryReportRenderer::fileName($report, 'pdf'), 'application/pdf');
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            throw new RuntimeException('Sending the advisory digest failed: ' . $exception->getMessage(), previous: $exception);
        }

        return $recipients;
    }

    public function subject(AdvisoryReport $report): string
    {
        $highOrWorse = $report->severityCounts['critical'] + $report->severityCounts['high'];

        return sprintf(
            '%s [ADVISORIES] %s %s – %s: %d advisories, %d critical or high',
            $report->total === 0 ? '🟢' : AdvisoryReportPresenter::severity($report->worstSeverity()->value)['emoji'],
            $report->title,
            $report->query->since->format('Y-m-d'),
            $report->query->until->format('Y-m-d'),
            $report->total,
            $highOrWorse,
        );
    }
}
