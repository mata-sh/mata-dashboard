<?php

declare(strict_types=1);

namespace Mata\Cli;

use Mata\Services\Status\StatusLevel;

/** One dataset read from a node by NodeDatasetReader, or why it could not be read. */
final readonly class NodeDatasetResult
{
    /**
     * @param array<string,mixed>      $provenance Provenance::fromNode() fields; empty when no response came back
     * @param null|array<string,mixed> $notice     the `code` and `message` the node added to its data
     * @param null|array<string,mixed> $nodeError  the error the node answered with
     */
    private function __construct(
        public ?string $error,
        public array $provenance,
        public mixed $result,
        public ?array $notice = null,
        public ?array $nodeError = null,
    ) {}

    /**
     * @param array<string,mixed>      $provenance
     * @param null|array<string,mixed> $nodeError
     */
    public static function failed(string $error, array $provenance = [], ?array $nodeError = null): self
    {
        return new self($error, $provenance, null, nodeError: $nodeError);
    }

    /**
     * @param array<string,mixed>      $provenance
     * @param null|array<string,mixed> $notice
     */
    public static function succeeded(array $provenance, mixed $result, ?array $notice = null): self
    {
        return new self(null, $provenance, $result, notice: $notice);
    }

    /**
     * The JSON fields that describe the read: the provenance, then the node's `notice` or `node_error`.
     *
     * @return array<string,mixed>
     */
    public function fields(): array
    {
        return $this->provenance
            + ($this->notice !== null ? ['notice' => $this->notice] : [])
            + ($this->nodeError !== null ? ['node_error' => $this->nodeError] : []);
    }

    /** Error without data, warning for a copy past its TTL. */
    public function level(): StatusLevel
    {
        return match (true) {
            $this->error !== null => StatusLevel::Error,
            ($this->provenance['cache']['stale'] ?? false) === true => StatusLevel::Warning,
            default => StatusLevel::Ok,
        };
    }
}
