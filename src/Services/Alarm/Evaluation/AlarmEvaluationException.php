<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Evaluation;

use RuntimeException;
use Throwable;

final class AlarmEvaluationException extends RuntimeException
{
    public const POLICY = 'policy';
    public const DELIVERY = 'delivery';
    public const COMPLETION = 'completion';

    public function __construct(
        private string $stage,
        Throwable $previous,
        private bool $enrichmentRequested = false,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    /** Return the processing stage that failed. */
    public function getStage(): string
    {
        return $this->stage;
    }

    /** Return whether policy requested enrichment before the failure. */
    public function isEnrichmentRequested(): bool
    {
        return $this->enrichmentRequested;
    }
}
