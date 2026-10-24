<?php

declare(strict_types=1);

namespace Mata\Models\Advisory;

/** Operator triage of an advisory. Persisted in advisories.review_status. */
enum AdvisoryReviewStatus: string
{
    case New = 'new';
    case Reviewed = 'reviewed';
    case NotRelevant = 'not_relevant';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Reviewed => 'Reviewed',
            self::NotRelevant => 'Not relevant',
        };
    }
}
