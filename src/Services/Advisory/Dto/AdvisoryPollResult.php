<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Dto;

/** Outcome of polling one source. */
final readonly class AdvisoryPollResult
{
    /**
     * @param list<array{item:AdvisoryItem,relevance:AdvisoryRelevance}> $preview fetched items of a preview, newest first
     */
    public function __construct(
        public string $sourceKey,
        public AdvisoryPollStatus $status,
        public int $fetched = 0,
        public int $stored = 0,
        public int $relevant = 0,
        public int $notified = 0,
        public bool $baseline = false,
        public ?string $error = null,
        public int $consecutiveFailures = 0,
        public array $preview = [],
    ) {}

    public function summaryLine(): string
    {
        return match ($this->status) {
            AdvisoryPollStatus::NotDue => 'not due',
            AdvisoryPollStatus::Failed => "failed ({$this->consecutiveFailures} in a row): {$this->error}",
            AdvisoryPollStatus::Previewed => "fetched={$this->fetched} relevant={$this->relevant} (preview, nothing stored)",
            AdvisoryPollStatus::Polled => "fetched={$this->fetched} new={$this->stored} relevant={$this->relevant} notified={$this->notified}"
                . ($this->baseline ? ' (baseline: stored without notifying)' : ''),
        };
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceKey,
            'status' => $this->status->value,
            'fetched' => $this->fetched,
            'new' => $this->stored,
            'relevant' => $this->relevant,
            'notified' => $this->notified,
            'baseline' => $this->baseline,
            'error' => $this->error,
            'consecutive_failures' => $this->consecutiveFailures,
        ];
    }
}
