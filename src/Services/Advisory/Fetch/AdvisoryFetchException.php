<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Fetch;

use RuntimeException;

/** A source could not be fetched or parsed. The message is shown to operators and never holds secrets. */
final class AdvisoryFetchException extends RuntimeException {}
