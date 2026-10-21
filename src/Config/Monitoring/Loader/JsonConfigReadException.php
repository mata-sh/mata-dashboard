<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use RuntimeException;
use Throwable;

/** Retains the failed file independently of the human-readable error message. */
final class JsonConfigReadException extends RuntimeException
{
    public function __construct(string $message, public readonly string $fileName, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
