<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery;

use Mata\Config\Dto\MailConfig;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use RuntimeException;
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
        $recipients = $this->mailConfig->recipients();
        if ($recipients === []) {
            throw new RuntimeException('Alarm email recipients not configured (MAIL_ALARM_RECIPIENTS).');
        }
        $sender = $this->mailConfig->sender($this->templateData->applicationName);

        $data = $this->templateData->create($view);

        try {
            $html = $this->twig->render('emails/alarm.html.twig', $data);
            $text = $this->twig->render('emails/alarm.txt.twig', $data);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to render alarm email templates.', previous: $exception);
        }

        return (new Email())
            ->from($sender)
            ->to(...MailConfig::addresses($recipients))
            ->subject($this->createSubject($view, $data['alarm']['title']))
            ->html($html, $this->mailConfig->charset)
            ->text($text, $this->mailConfig->charset);
    }

    private function createSubject(AlarmDeliveryView $view, string $title): string
    {
        $alarm = $view->alarm;
        if (str_starts_with($alarm->type, 'advisories.') && trim((string) $alarm->app) === '' && trim((string) $alarm->server) === '') {
            return sprintf('%s [ADVISORY] %s', $alarm->level->getEmoji(), $title);
        }
        $app = $this->templateData->singleLine($alarm->app, '*');
        $server = $this->templateData->singleLine($alarm->server, '*');

        return sprintf('%s [%s.%s] %s', $alarm->level->getEmoji(), $app, $server, $title);
    }
}
