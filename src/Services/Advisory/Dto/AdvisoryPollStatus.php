<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Dto;

enum AdvisoryPollStatus: string
{
    case Polled = 'polled';
    case NotDue = 'not_due';
    case Failed = 'failed';
    case Previewed = 'previewed';
}
