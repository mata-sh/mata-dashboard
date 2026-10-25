<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section\Advisory;

/** How an `imap` advisory source secures its connection; credentials are never sent unencrypted. */
enum ImapEncryption: string
{
    /** Implicit TLS from the first byte. */
    case Ssl = 'ssl';

    /** Plain connection upgraded with STARTTLS before login. */
    case Starttls = 'starttls';

    public function defaultPort(): int
    {
        return match ($this) {
            self::Ssl => 993,
            self::Starttls => 143,
        };
    }
}
