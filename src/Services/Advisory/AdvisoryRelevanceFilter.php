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
            return new AdvisoryRelevance(relevant: false, matchedKeywords: []);
        }
        if ($source->keywords === []) {
            return new AdvisoryRelevance(relevant: true, matchedKeywords: []);
        }

        $text = self::searchableText($item);
        $matched = [];
        foreach ($source->keywords as $keyword) {
            if (self::containsWord($text, $keyword)) {
                $matched[] = $keyword;
            }
        }

        return new AdvisoryRelevance(relevant: $matched !== [], matchedKeywords: $matched);
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

    /**
     * Title, summary and products, one per line, so a keyword never matches across two fields.
     * Invalid UTF-8 is replaced: it makes a /u match fail, which would drop the advisory.
     */
    private static function searchableText(AdvisoryItem $item): string
    {
        $fields = [$item->title, $item->summary ?? '', ...$item->products];

        return mb_scrub(implode("\n", $fields), 'UTF-8');
    }

    /** Not touching a letter or digit, unlike \b, so "C++" and ".NET" match too. */
    private static function containsWord(string $text, string $word): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1;
    }
}
