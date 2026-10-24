<?php

declare(strict_types=1);

namespace Mata\Services\Advisory;

use Mata\Services\Audit\Severity;

/**
 * Text normalization shared by the fetchers: plain text from markup, CVE IDs, and severity
 * words in English and German (CERT-Bund publishes niedrig/mittel/hoch/kritisch).
 */
final class AdvisoryText
{
    public const SUMMARY_LIMIT = 2000;
    public const TITLE_LIMIT = 500;

    /** Longest words first, so "moderate" is not read as a shorter word inside it. */
    private const SEVERITY_WORDS = [
        'critical' => Severity::Critical, 'kritisch' => Severity::Critical,
        'important' => Severity::High, 'high' => Severity::High, 'hoch' => Severity::High,
        'moderate' => Severity::Medium, 'medium' => Severity::Medium, 'mittel' => Severity::Medium,
        'low' => Severity::Low, 'niedrig' => Severity::Low,
    ];

    /**
     * Collapses markup and whitespace; null when nothing readable is left. Only tag-like
     * `<…>` goes: strip_tags() would also drop "<8.4.26, …" up to the next ">".
     */
    public static function plain(?string $text, int $limit = self::SUMMARY_LIMIT): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = preg_replace(['/<(br|\/p|\/li|\/div)\b[^>]*>/i', '/<[a-z\/!?][^>]*>/i'], [' ', ''], $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)) . '…' : $text;
    }

    /** Plain text from a Markdown description (GitHub, OSV). */
    public static function plainFromMarkdown(?string $markdown): ?string
    {
        if ($markdown === null) {
            return null;
        }

        // Section headings ("### Summary") carry no content of their own.
        // Identifiers like cleanup_xml_node keep their underscores; only emphasis and code markers go.
        return self::plain(preg_replace(['/^#{1,6}\s.*$/m', '/`+|\*{1,3}(?=\S)|(?<=\S)\*{1,3}/', '/^\s*>\s?/m', '/\[([^\]]+)\]\([^)]+\)/'], ['', '', '', '$1'], $markdown) ?? $markdown);
    }

    /** @return list<string> upper-case CVE IDs in order of appearance */
    public static function cveIds(string ...$texts): array
    {
        preg_match_all('/\bCVE-\d{4}-\d{4,}\b/i', implode(' ', $texts), $matches);

        return array_values(array_unique(array_map('strtoupper', $matches[0])));
    }

    /**
     * The first severity word in the given texts, in order: pass structured fields (feed
     * categories) before free text (titles), so a field wins over a word in prose.
     */
    public static function severity(?string ...$texts): Severity
    {
        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }
            if (preg_match('/\b(' . implode('|', array_keys(self::SEVERITY_WORDS)) . ')\b/iu', $text, $match) === 1) {
                return self::SEVERITY_WORDS[strtolower($match[1])];
            }
        }

        return Severity::Unknown;
    }

    /** CVSS v3/v4 qualitative rating. */
    public static function severityFromCvss(?float $score): Severity
    {
        return match (true) {
            $score === null || $score <= 0.0 => Severity::Unknown,
            $score >= 9.0 => Severity::Critical,
            $score >= 7.0 => Severity::High,
            $score >= 4.0 => Severity::Medium,
            default => Severity::Low,
        };
    }

    /**
     * Product named before the first colon of a title, after leading "[NEU] [hoch]" style tags:
     * "[UPDATE] [hoch] PHP: Schwachstelle …" names "PHP".
     */
    public static function productFromTitle(string $title): ?string
    {
        $title = trim(preg_replace('/^(\s*\[[^\]]{1,20}\])+/u', '', $title) ?? $title);
        $colon = mb_strpos($title, ': ');
        if ($colon === false || $colon < 2 || $colon > 100) {
            return null;
        }

        return trim(mb_substr($title, 0, $colon));
    }

    /** Titles are single-line and bounded; feeds occasionally carry markup or line breaks. */
    public static function title(?string $title, string $fallback): string
    {
        return self::plain($title, self::TITLE_LIMIT) ?? $fallback;
    }
}
