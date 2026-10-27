<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use DateTimeImmutable;
use Mata\Config\Dto\MailConfig;
use Mata\Services\Alarm\Delivery\AlarmEmailFactory;
use Mata\Services\Alarm\Delivery\AlarmEmailSender;
use Mata\Services\Alarm\Delivery\AlarmTemplateDataFactory;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Twig\Loader\ArrayLoader;

/**
 * @covers \Mata\Services\Alarm\Delivery\AlarmEmailSender
 *
 * @internal
 */
final class AlarmEmailSenderTest extends TestCase
{
    public function testItPassesTheFactoryProducedEmailToTheMailerOnce(): void
    {
        $mailer = new CapturingAlarmMailer();
        $sender = new AlarmEmailSender($this->factory(), $mailer);

        $sender->send($this->view());

        $this->assertCount(1, $mailer->messages);
        $this->assertSame('🔵 [app.server] Type', $mailer->messages[0]->getSubject());
    }

    public function testFactoryExceptionsPreventTransportCallsAndPropagate(): void
    {
        $mailer = new CapturingAlarmMailer();
        $factory = new AlarmEmailFactory(
            new ArrayLoader(),
            new AlarmTemplateDataFactory('MATA', null),
            new MailConfig(null, null, null, [], 'UTF-8', null),
        );
        $sender = new AlarmEmailSender($factory, $mailer);

        $this->expectException(RuntimeException::class);

        try {
            $sender->send($this->view());
        } finally {
            $this->assertCount(0, $mailer->messages);
        }
    }

    private function factory(): AlarmEmailFactory
    {
        return new AlarmEmailFactory(
            new ArrayLoader([
                'emails/alarm.html.twig' => '<p>{{ alarm.message }}</p>',
                'emails/alarm.txt.twig' => '{{ alarm.message }}',
            ]),
            new AlarmTemplateDataFactory('MATA', null),
            new MailConfig(null, 'alerts@example.com', 'Alerts', ['ops@example.com'], 'UTF-8', null),
        );
    }

    private function view(): AlarmDeliveryView
    {
        return new AlarmDeliveryView(
            alarm: new Alarm('type', 'Message', null, server: 'server', app: 'app'),
            alarmId: 1,
            alarmCreatedAt: new DateTimeImmutable(),
            eventId: 1,
            eventOccurredAt: new DateTimeImmutable(),
        );
    }
}

final class CapturingAlarmMailer implements MailerInterface
{
    /** @var array<int,RawMessage> */
    public array $messages = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->messages[] = $message;
    }
}
