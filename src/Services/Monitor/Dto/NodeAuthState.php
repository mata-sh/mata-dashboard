<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

/** Outcome of the signed request `bin/mata server test` sends to a node. */
enum NodeAuthState: string
{
    case Ok = 'ok';
    case Failed = 'failed';

    /** No api_key is stored, so no signed request was sent. */
    case Skipped = 'skipped';
}
