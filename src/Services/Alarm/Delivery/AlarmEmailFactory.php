<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery;

use Mata\Config\Dto\MailConfig;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use Twig\Environment;
use Twig\Loader\LoaderInterface;

/** Creates complete email messages for queued alarm deliveries. */
final class AlarmEmailFactory
{
    private Environment $twig;

    public function __construct(
        LoaderInterface $templates,
        private AlarmTemplateDataFactory $templateData,
        private MailConfig $mailConfig,
    ) {
        // Escape by template extension: HTML for alarm.html.twig, nothing for the plain-text part.
        $this->twig = new Environment($templates, ['autoescape' => 'name']);
    }

    /** @throws RuntimeException */
    public function create(AlarmDeliveryView $view): Email
    {
        $recipients = array_values(array_filter(
            $this->mailConfig->alarmRecipients,
            static fn (string $recipient): bool => trim($recipient) !== '',
        ));
        if ($recipients === []) {
            throw new RuntimeException('Alarm email recipients not configured.');
        }

        $fromEmail = trim((string) $this->mailConfig->fromEmail);
        if ($fromEmail === '') {
            throw new RuntimeException('Alarm email sender address not configured.');
        }

        $fromName = trim((string) $this->mailConfig->fromName);
        if ($fromName === '') {
            $fromName = $this->templateData->applicationName . ' Dashboard';
        }

        $data = $this->templateData->create($view);

        try {
            $html = $this->twig->render('emails/alarm.html.twig', $data);
            $text = $this->twig->render('emails/alarm.txt.twig', $data);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to render alarm email templates.', 0, $exception);
        }

        $recipientAddresses = array_map(
            static fn (string $recipient): Address => new Address($recipient),
            $recipients,
        );

        return (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to(...$recipientAddresses)
            ->subject($this->createSubject($view, $data['alarm']['title']))
            ->html($html, $this->mailConfig->charset)
            ->text($text, $this->mailConfig->charset);
    }

    private function createSubject(AlarmDeliveryView $view, string $title): string
    {
        $alarm = $view->alarm;
        $app = $this->templateData->singleLine($alarm->app, '*');
        $server = $this->templateData->singleLine($alarm->server, '*');

        return sprintf('%s [%s.%s] %s', $alarm->level->getEmoji(), $app, $server, $title);
    }
}
