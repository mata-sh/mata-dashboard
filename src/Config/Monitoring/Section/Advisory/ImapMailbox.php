<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section\Advisory;

use SensitiveParameter;

/** Mailbox of an `imap` advisory source. Credentials come from the environment and never reach logs. */
final readonly class ImapMailbox
{
    /**
     * @param list<string> $addresses lowercase addresses or list IDs; a message is accepted when its From, Sender,
     *                                To, List-Id or List-Post header contains one. Empty accepts every message.
     */
    public function __construct(
        public string $host,
        public int $port,
        public ImapEncryption $encryption,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public string $folder,
        public array $addresses,
    ) {}

    /** Shown where other sources show their URL; carries no credentials. */
    public function displayUrl(): string
    {
        return 'imap://' . $this->host . ':' . $this->port . '/' . rawurlencode($this->folder);
    }
}
