<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Dto\MailConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Mata\Config\Dto\MailConfig
 *
 * @internal
 */
final class MailConfigTest extends TestCase
{
    public function testRecipientsSkipListsWithOnlyBlankAddresses(): void
    {
        $config = $this->config(alarmRecipients: ['ops@example.com']);

        $this->assertSame(['ops@example.com'], $config->recipients(['  '], ['']));
        $this->assertSame(['sec@example.com'], $config->recipients([' sec@example.com ', ' ']));
    }

    public function testInvalidRecipientIsARuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid email address "not-an-address".');

        MailConfig::address('not-an-address');
    }

    public function testSenderFallsBackToTheApplicationName(): void
    {
        $sender = $this->config(fromName: ' ')->sender('MATA');

        $this->assertSame('alerts@example.com', $sender->getAddress());
        $this->assertSame('MATA Dashboard', $sender->getName());
    }

    /** @param list<string> $alarmRecipients */
    private function config(?string $fromName = null, array $alarmRecipients = []): MailConfig
    {
        return new MailConfig(null, 'alerts@example.com', $fromName, $alarmRecipients, [], [], 'UTF-8', null);
    }
}
