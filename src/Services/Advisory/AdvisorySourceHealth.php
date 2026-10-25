<?php

declare(strict_types=1);

namespace Mata\Services\Advisory;

use DateTimeImmutable;
use DateTimeZone;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Models\Advisory\AdvisorySourceState;
use Mata\Services\Status\StatusLevel;

/** How polling a source goes, as the Advisories page, the reports and `advisory:sources` show it. */
enum AdvisorySourceHealth: string
{
    case Disabled = 'disabled';
    case NeverPolled = 'never_polled';
    case Failing = 'failing';

    /** Not polled for its interval plus stale_after_minutes, e.g. because the poll cron stopped. */
    case Stale = 'stale';
    case Ok = 'ok';

    /** @param int $staleAfterMinutes advisories.json stale_after_minutes, the poll watchdog's threshold */
    public static function of(AdvisorySource $source, ?AdvisorySourceState $state, DateTimeImmutable $now, int $staleAfterMinutes): self
    {
        // The source's own interval first, so a weekly source is not stale after six hours.
        $staleBefore = $now->setTimezone(new DateTimeZone('UTC'))
            ->modify(sprintf('-%d minutes', $source->pollIntervalMinutes + $staleAfterMinutes))
            ->format('Y-m-d H:i:s');

        return match (true) {
            !$source->enabled => self::Disabled,
            $state === null || ($state->lastSuccessAt === null && $state->consecutiveFailures === 0) => self::NeverPolled,
            $state->consecutiveFailures > 0 => self::Failing,
            $state->lastPolledAt < $staleBefore => self::Stale,
            default => self::Ok,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled',
            self::NeverPolled => 'Not polled yet',
            self::Failing => 'Failing',
            self::Stale => 'Stale',
            self::Ok => 'OK',
        };
    }

    public function level(): StatusLevel
    {
        return match ($this) {
            self::Failing => StatusLevel::Error,
            self::NeverPolled, self::Stale => StatusLevel::Warning,
            self::Ok, self::Disabled => StatusLevel::Ok,
        };
    }
}
