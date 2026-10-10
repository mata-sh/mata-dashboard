<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery;

use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;

/** Channel-specific email sender for alarm delivery dispatch. */
final class AlarmEmailSender implements AlarmChannelSenderInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private AlarmEmailFactory $emailFactory,
        private MailerInterface $mailer,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function send(AlarmDeliveryView $view): void
    {
        $email = $this->emailFactory->create($view);

        $this->logger->debug('Sending alarm email.', [
            'alarm_id' => $view->alarmId,
            'type' => $view->alarm->type,
            'level' => $view->alarm->level->value,
            'server' => $view->alarm->server,
            'app' => $view->alarm->app,
            'subject' => $email->getSubject(),
            'recipient_count' => count($email->getTo()),
        ]);

        $this->mailer->send($email);
    }
}
