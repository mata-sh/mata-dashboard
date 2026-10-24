<?php

declare(strict_types=1);

namespace Mata\Services\Advisory;

use Mata\Config\Dto\MailConfig;
use Mata\Config\Monitoring\Section\Advisory\AdvisoryDigestConfig;
use Mata\Services\Advisory\Report\AdvisoryReport;
use Mata\Services\Advisory\Report\AdvisoryReportPresenter;
use Mata\Services\Advisory\Report\AdvisoryReportRenderer;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Mails an advisory report: the HTML report as body, the Markdown as text part, and the PDF
 * attached when advisories.digest.attach_pdf is set. The subject follows the alarm convention
 * with the report's worst severity: "🔴 [ADVISORIES] Security advisory report …". The plural
 * tag tells the digest apart from the [ADVISORY] alarms for single advisories.
 */
final class AdvisoryDigestMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private MailConfig $mailConfig,
        private AdvisoryDigestConfig $digest,
        private AdvisoryReportRenderer $renderer,
        private string $applicationName,
    ) {}

    /**
     * @param list<string> $recipients overrides advisories.digest.recipients, then MAIL_ALARM_RECIPIENTS
     *
     * @return list<string> the recipients mailed
     *
     * @throws RuntimeException when mail is not configured, an address is invalid or the transport fails
     */
    public function send(AdvisoryReport $report, array $recipients = []): array
    {
        $recipients = $this->mailConfig->recipients($recipients, $this->digest->recipients);
        if ($recipients === []) {
            throw new RuntimeException('No digest recipients: set advisories.digest.recipients or MAIL_ALARM_RECIPIENTS.');
        }
        $email = $this->createEmail($report, $recipients);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            throw new RuntimeException('Sending the advisory digest failed: ' . $exception->getMessage(), previous: $exception);
        }

        return $recipients;
    }

    /**
     * @param list<string> $recipients
     *
     * @throws RuntimeException when the sender or a recipient address is invalid
     */
    private function createEmail(AdvisoryReport $report, array $recipients): Email
    {
        $email = (new Email())
            ->from($this->mailConfig->sender($this->applicationName))
            ->to(...MailConfig::addresses($recipients))
            ->subject($this->subject($report))
            ->html($this->renderer->html($report), $this->mailConfig->charset)
            ->text($this->renderer->markdown($report), $this->mailConfig->charset);
        if ($this->digest->attachPdf) {
            $email->attach(
                body: $this->renderer->pdf($report),
                name: AdvisoryReportRenderer::fileName($report, 'pdf'),
                contentType: 'application/pdf',
            );
        }

        return $email;
    }

    private function subject(AdvisoryReport $report): string
    {
        return sprintf(
            '%s [ADVISORIES] %s %s – %s: %d advisories, %d critical or high',
            AdvisoryReportPresenter::worst($report)['emoji'],
            $report->title,
            AdvisoryReportPresenter::day($report->query->since),
            AdvisoryReportPresenter::day($report->query->until),
            $report->total,
            $report->criticalOrHighCount(),
        );
    }
}
