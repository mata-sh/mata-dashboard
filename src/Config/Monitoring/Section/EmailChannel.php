<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

/** One extra email alarm channel. It shares the SMTP settings of mail.config.env, only the recipients differ. */
final readonly class EmailChannel
{
    /** @param non-empty-list<string> $recipients */
    public function __construct(
        public string $name,
        public array $recipients,
    ) {}
}
