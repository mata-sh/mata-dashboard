<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section\Advisory;

/** The periodic advisory report mailed by cron/advisories_digest.php. */
final readonly class AdvisoryDigestConfig
{
    /** @param list<string> $recipients empty: MAIL_ALARM_RECIPIENTS */
    public function __construct(
        public bool $enabled,
        public int $everyDays,
        public array $recipients,
        public bool $relevantOnly,
        public bool $attachPdf,
    ) {}

    public static function disabled(): self
    {
        return new self(false, 7, [], true, true);
    }
}
