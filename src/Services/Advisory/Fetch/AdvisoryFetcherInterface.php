<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Fetch;

use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;

interface AdvisoryFetcherInterface
{
    /**
     * Fetch the advisories the source currently lists. Duplicates of stored ones are fine;
     * the poller skips them.
     *
     * @param ?string $cursor the cursor the previous fetch returned
     *
     * @throws AdvisoryFetchException
     */
    public function fetch(AdvisorySource $source, ?string $cursor): AdvisoryFetchResult;
}
