<?php

declare(strict_types=1);

namespace Mata\Services\Advisory;

use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use Mata\Services\Advisory\Dto\AdvisoryRelevance;
use Mata\Services\Audit\Severity;

/**
 * Applies a source's filters. Keywords match whole words, case-insensitively, in the title,
 * summary and products ("PHP" matches "PHP 8.4", not "PHPMailer"). An advisory without a
 * known severity passes the severity filter: a mailing-list post must not vanish because
 * it carries no rating.
 */
final class AdvisoryRelevanceFilter
{
    public function evaluate(AdvisorySource $source, AdvisoryItem $item): AdvisoryRelevance
    {
        if (!self::meetsSeverity($item->severity, $source->minSeverity)) {
            return new AdvisoryRelevance(false, []);
        }
        if ($source->keywords === []) {
            return new AdvisoryRelevance(true, []);
        }

        $haystack = implode("\n", [$item->title, $item->summary ?? '', ...$item->products]);
        $matched = [];
        foreach ($source->keywords as $keyword) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $haystack) === 1) {
                $matched[] = $keyword;
            }
        }

        return new AdvisoryRelevance($matched !== [], $matched);
    }

    /** Severity cases are declared worst first, so a lower index is more severe. */
    public static function meetsSeverity(Severity $severity, Severity $minimum): bool
    {
        if ($severity === Severity::Unknown) {
            return true;
        }
        $rank = array_flip(Severity::values());

        return $rank[$severity->value] <= $rank[$minimum->value];
    }
}
