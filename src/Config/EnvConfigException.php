<?php

declare(strict_types=1);

namespace Mata\Config;

use InvalidArgumentException;
use Throwable;

/** Names the env variable or file that broke loading, separately from the message. */
final class EnvConfigException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly ConfigSource $source, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
