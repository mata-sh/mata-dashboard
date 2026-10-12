<?php

declare(strict_types=1);

namespace Mata\Services\Cron\Dto;

enum CronJobState: string
{
    case Ok = 'ok';
    case Overdue = 'overdue';

    /** Ran, but not often enough yet to infer its interval. */
    case Unknown = 'unknown';
    case Never = 'never';

    /** Has not run yet, but cron started less than a day ago: daily jobs may still be waiting. */
    case Pending = 'pending';
}
