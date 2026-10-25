<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section\Advisory;

use InvalidArgumentException;
use Mata\Config\Monitoring\ConfigObject;

/** The periodic advisory report mailed by cron/advisories_digest.php. */
final readonly class AdvisoryDigestConfig
{
    /** Also the report page's default range, so it applies while the digest is disabled. */
    private const DEFAULT_EVERY_DAYS = 7;
    private const DEFAULT_RELEVANT_ONLY = true;
    private const DEFAULT_ATTACH_PDF = true;
    private const KEYS = ['enabled', 'every_days', 'recipients', 'relevant_only', 'attach_pdf'];

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
        return new self(
            enabled: false,
            everyDays: self::DEFAULT_EVERY_DAYS,
            recipients: [],
            relevantOnly: self::DEFAULT_RELEVANT_ONLY,
            attachPdf: self::DEFAULT_ATTACH_PDF,
        );
    }

    /**
     * A present but empty `digest` object enables the digest with the defaults.
     *
     * @throws InvalidArgumentException when a key is unknown or invalid
     */
    public static function fromConfig(ConfigObject $digest): self
    {
        $digest->rejectUnknownKeys(self::KEYS);

        return new self(
            enabled: $digest->boolean('enabled', true),
            everyDays: $digest->integer('every_days', self::DEFAULT_EVERY_DAYS, 1, 92),
            recipients: $digest->stringList('recipients', self::isEmail(...), 'a list of email addresses'),
            relevantOnly: $digest->boolean('relevant_only', self::DEFAULT_RELEVANT_ONLY),
            attachPdf: $digest->boolean('attach_pdf', self::DEFAULT_ATTACH_PDF),
        );
    }

    private static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
