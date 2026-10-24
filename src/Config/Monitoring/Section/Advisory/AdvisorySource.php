<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section\Advisory;

use Mata\Services\Audit\Severity;

/** One advisory source from advisories.json, keyed by a stable identifier. */
final readonly class AdvisorySource
{
    /**
     * @param list<string> $keywords case-insensitive whole words; empty matches every advisory
     * @param string       $url      feed or CSAF index URL, GitHub API URL, OSV.dev page, or an imap:// display URL
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public AdvisorySourceType $type,
        public string $url,
        public bool $enabled,
        public int $pollIntervalMinutes,
        public array $keywords,
        public Severity $minSeverity,
        /** False lists the source's advisories without recording `advisories.matched` events. */
        public bool $notify,
        public ?GithubRepository $github = null,
        public ?ImapMailbox $mailbox = null,
        public ?OsvPackage $package = null,
    ) {}
}
