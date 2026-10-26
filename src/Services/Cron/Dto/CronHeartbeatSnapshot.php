<?php

declare(strict_types=1);

namespace Mata\Services\Cron\Dto;

final readonly class CronHeartbeatSnapshot
{
    /**
     * @param string   $head         Start of the heartbeat file, with the job's first heartbeat
     * @param string   $tail         End of the heartbeat file, with its recent heartbeats
     * @param null|int $lastModified File mtime, the last heartbeat; unlike "ts" it does not depend on
     *                               PHP's timezone. Null when the file is missing or unreadable
     */
    public function __construct(
        public string $head = '',
        public string $tail = '',
        public ?int $lastModified = null,
    ) {}
}
