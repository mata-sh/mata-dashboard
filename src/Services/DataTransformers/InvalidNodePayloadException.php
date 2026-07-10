<?php

declare(strict_types=1);

namespace Mata\Services\DataTransformers;

use RuntimeException;

/**
 * Thrown by payload normalizers when a node returns structurally invalid data.
 */
final class InvalidNodePayloadException extends RuntimeException
{
    public function __construct(string $message, public readonly string $endpoint)
    {
        parent::__construct($message);
    }

    /** @return array{kind:string,message:string,endpoint:string,status_code:null|int} */
    public function toErrorArray(): array
    {
        return [
            'kind' => 'invalid_payload',
            'message' => $this->getMessage(),
            'endpoint' => $this->endpoint,
            'status_code' => null,
        ];
    }
}
