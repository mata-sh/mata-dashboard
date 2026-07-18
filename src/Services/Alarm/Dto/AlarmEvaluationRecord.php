<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

/** Records the outcome and evidence of one alarm evaluation. */
final class AlarmEvaluationRecord
{
    private string $outcome = 'suppressed';
    private string $thresholdGate = 'fail';
    private ?string $policyGate = null;
    private ?string $policyReason = null;

    /** @var array<int,string> */
    private array $requestedChannels = [];

    /** @var array<int,string> */
    private array $readyChannels = [];

    /** @var array<int,string> */
    private array $unavailableChannels = [];

    private ?int $priority = null;
    private bool $enrich = false;
    private bool $requestedChannelsSet = false;
    private bool $readyChannelsSet = false;

    /** Set the final evaluation outcome. */
    public function setOutcome(string $outcome): self
    {
        $this->outcome = $outcome;

        return $this;
    }

    /** Set the threshold evaluation result. */
    public function setThresholdGate(string $thresholdGate): self
    {
        $this->thresholdGate = $thresholdGate;

        return $this;
    }

    /** Set the policy evaluation result. */
    public function setPolicyGate(?string $policyGate): self
    {
        $this->policyGate = $policyGate;

        return $this;
    }

    /** Set the policy-provided reason. */
    public function setPolicyReason(?string $policyReason): self
    {
        $this->policyReason = $policyReason;

        return $this;
    }

    /**
     * Set the channels requested by policy.
     *
     * @param array<int,string> $requestedChannels
     */
    public function setRequestedChannels(array $requestedChannels): self
    {
        $this->requestedChannels = $requestedChannels;
        $this->requestedChannelsSet = true;
        $this->updateUnavailableChannels();

        return $this;
    }

    /**
     * Set the channels available after cooldown filtering.
     *
     * @param array<int,string> $readyChannels
     */
    public function setReadyChannels(array $readyChannels): self
    {
        $this->readyChannels = $readyChannels;
        $this->readyChannelsSet = true;
        $this->updateUnavailableChannels();

        return $this;
    }

    /** Set the policy-provided delivery priority. */
    public function setPriority(?int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /** Set whether enrichment was requested by policy. */
    public function setEnrich(bool $enrich): self
    {
        $this->enrich = $enrich;

        return $this;
    }

    /**
     * Return the record for logging and persistence.
     *
     * @return array{
     *     outcome:string,
     *     threshold_gate:string,
     *     policy_gate:?string,
     *     policy_reason:?string,
     *     requested_channels:array<int,string>,
     *     ready_channels:array<int,string>,
     *     unavailable_channels:array<int,string>,
     *     priority:?int,
     *     enrich:bool
     * }
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'threshold_gate' => $this->thresholdGate,
            'policy_gate' => $this->policyGate,
            'policy_reason' => $this->policyReason,
            'requested_channels' => $this->requestedChannels,
            'ready_channels' => $this->readyChannels,
            'unavailable_channels' => $this->unavailableChannels,
            'priority' => $this->priority,
            'enrich' => $this->enrich,
        ];
    }

    /** Derive unavailable channels after both channel sets are known. */
    private function updateUnavailableChannels(): void
    {
        if (!$this->requestedChannelsSet || !$this->readyChannelsSet) {
            return;
        }

        $this->unavailableChannels = array_values(array_diff(
            $this->requestedChannels,
            $this->readyChannels
        ));
    }
}
