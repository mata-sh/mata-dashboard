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

    /** Matched as whole words, case-insensitively. */
    private const SEVERITY_WORDS = [
        'critical' => Severity::Critical, 'kritisch' => Severity::Critical,
        'important' => Severity::High, 'high' => Severity::High, 'hoch' => Severity::High,
        'moderate' => Severity::Medium, 'medium' => Severity::Medium, 'mittel' => Severity::Medium,
        'low' => Severity::Low, 'niedrig' => Severity::Low,
    ];

    /** Tags that end a line. They become a space, so the words on either side stay apart. */
    private const LINE_BREAK_TAGS = ['br', '/p', '/li', '/div'];

    private const ASCII_WORD_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    /** Leading title tags like "[UPDATE]" are this long at most; a longer bracket belongs to the title. */
    private const TITLE_TAG_MAX_LENGTH = 20;

    /** A product name before a title's colon is this long; outside that range the colon is prose. */
    private const PRODUCT_MIN_LENGTH = 2;
    private const PRODUCT_MAX_LENGTH = 100;

    /** Collapses markup and whitespace; null when nothing readable is left. */
    public static function plain(?string $text, int $limit = self::SUMMARY_LIMIT): ?string
    {
        if ($text === null) {
            return null;
        }
        $withoutTags = self::removeTags($text);
        $decoded = html_entity_decode($withoutTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = self::collapseWhitespace($decoded);
        if ($plain === '') {
            return null;
        }
        if (mb_strlen($plain) <= $limit) {
            return $plain;
        }

        $shortened = rtrim(mb_substr($plain, 0, $limit - 1));

        return $shortened . '…';
    }

    /** Plain text from a Markdown description (GitHub, OSV). */
    public static function plainFromMarkdown(?string $markdown): ?string
    {
        if ($markdown === null) {
            return null;
        }

        $lines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $line) {
            // Section headings ("### Summary") carry no content of their own.
            if (self::isMarkdownHeading($line)) {
                continue;
            }
            $line = self::withoutCodeAndEmphasisMarkers($line);
            $lines[] = self::withoutQuoteMarker($line);
        }
        // After joining, as wrapped Markdown can break a link text across lines.
        $text = self::withLinkTextsOnly(implode("\n", $lines));

        return self::plain($text);
    }

    /** @return list<string> upper-case CVE IDs in order of appearance */
    public static function cveIds(string ...$texts): array
    {
        $text = implode(' ', $texts);
        $ids = [];
        $offset = 0;
        while (($start = stripos($text, 'CVE-', $offset)) !== false) {
            $id = self::cveIdAt($text, $start);
            if ($id !== null) {
                $ids[] = $id;
            }
            $offset = $start + 1;
        }

        return array_values(array_unique($ids));
    }

    /**
     * The first severity word in the given texts, in order: pass structured fields (feed
     * categories) before free text (titles), so a field wins over a word in prose.
     */
    public static function severity(?string ...$texts): Severity
    {
        foreach ($texts as $text) {
            foreach (self::words($text ?? '') as $word) {
                $severity = self::SEVERITY_WORDS[mb_strtolower($word)] ?? null;
                if ($severity !== null) {
                    return $severity;
                }
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
        $title = self::withoutLeadingTags($title);
        $colon = mb_strpos($title, ': ');
        $hasProductBeforeColon = $colon !== false
            && $colon >= self::PRODUCT_MIN_LENGTH
            && $colon <= self::PRODUCT_MAX_LENGTH;
        if (!$hasProductBeforeColon) {
            return null;
        }

        return trim(mb_substr($title, 0, $colon));
    }

    /** Titles are single-line and bounded; feeds occasionally carry markup or line breaks. */
    public static function title(?string $title, string $fallback): string
    {
        return self::plain($title, self::TITLE_LIMIT) ?? $fallback;
    }

    /**
     * Removes HTML tags; line-breaking ones become a space. A tag is a "<" followed by a letter,
     * "/", "!" or "?", up to the next ">", with no other "<" inside. strip_tags() would also drop
     * "<8.4.26, …" or "<v2 is affected <b>" up to the next ">".
     */
    private static function removeTags(string $text): string
    {
        $result = '';
        $offset = 0;
        while (($tagStart = strpos($text, '<', $offset)) !== false) {
            $tagEnd = strpos($text, '>', $tagStart);
            if ($tagEnd === false) {
                break;
            }

            $charAfterBracket = $text[$tagStart + 1];
            $nextTagStart = strpos($text, '<', $tagStart + 1);
            $containsTagStart = $nextTagStart !== false && $nextTagStart < $tagEnd;
            $startsTag = ctype_alpha($charAfterBracket) || in_array($charAfterBracket, ['/', '!', '?'], true);
            if (!$startsTag || $containsTagStart) {
                $result .= substr($text, $offset, $tagStart + 1 - $offset);
                $offset = $tagStart + 1;

                continue;
            }

            $tag = substr($text, $tagStart + 1, $tagEnd - $tagStart - 1);
            $result .= substr($text, $offset, $tagStart - $offset);
            $result .= self::isLineBreakTag($tag) ? ' ' : '';
            $offset = $tagEnd + 1;
        }

        return $result . substr($text, $offset);
    }

    /** @param string $tag the inside of a tag: "br/", "/p", "div class=\"x\"" */
    private static function isLineBreakTag(string $tag): bool
    {
        $closingSlash = str_starts_with($tag, '/') ? '/' : '';
        $nameStart = strlen($closingSlash);
        $nameLength = strspn($tag, self::ASCII_WORD_CHARS, $nameStart);
        $name = strtolower($closingSlash . substr($tag, $nameStart, $nameLength));

        return in_array($name, self::LINE_BREAK_TAGS, true);
    }

    /**
     * Turns every run of whitespace into one space, including Unicode spaces such as the
     * no-break space from "&nbsp;". Invalid UTF-8 fails the match; the text is then kept.
     */
    private static function collapseWhitespace(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($collapsed);
    }

    /** "# Title" to "###### Title": one to six "#" and a space. */
    private static function isMarkdownHeading(string $line): bool
    {
        $hashes = strspn($line, '#');
        $charAfterHashes = $line[$hashes] ?? '';

        return $hashes >= 1 && $hashes <= 6 && ctype_space($charAfterHashes);
    }

    /** "> quoted" becomes "quoted". */
    private static function withoutQuoteMarker(string $line): string
    {
        $trimmed = ltrim($line);
        if (!str_starts_with($trimmed, '>')) {
            return $line;
        }

        return substr($trimmed, 1);
    }

    /**
     * Drops the "`" of code and the "*", "**", "***" of emphasis. A lone "*", such as a bullet
     * or the "*" in "2 * 3", stays. Underscores stay too, so identifiers like cleanup_xml_node
     * survive.
     */
    private static function withoutCodeAndEmphasisMarkers(string $line): string
    {
        $words = explode(' ', $line);
        foreach ($words as $index => $word) {
            if ($word !== '*') {
                $words[$index] = str_replace(['`', '*'], '', $word);
            }
        }

        return implode(' ', $words);
    }

    /** "[text](url)" becomes "text". */
    private static function withLinkTextsOnly(string $markdown): string
    {
        $offset = 0;
        while (($textEnd = strpos($markdown, '](', $offset)) !== false) {
            $offset = $textEnd + 2;
            $textStart = strrpos(substr($markdown, 0, $textEnd), '[');
            $urlEnd = strpos($markdown, ')', $textEnd + 2);
            if ($textStart === false || $urlEnd === false) {
                continue;
            }

            $text = substr($markdown, $textStart + 1, $textEnd - $textStart - 1);
            $url = substr($markdown, $textEnd + 2, $urlEnd - $textEnd - 2);
            if ($text === '' || $url === '' || str_contains($text, ']')) {
                continue;
            }

            $markdown = substr($markdown, 0, $textStart) . $text . substr($markdown, $urlEnd + 1);
            $offset = $textStart + strlen($text);
        }

        return $markdown;
    }

    /**
     * The CVE ID starting at $start, such as "CVE-2026-1234": a four-digit year and a number
     * of at least four digits, not part of a longer word.
     */
    private static function cveIdAt(string $text, int $start): ?string
    {
        $charBefore = $start > 0 ? $text[$start - 1] : ' ';
        if (self::isWordChar($charBefore)) {
            return null;
        }

        $yearStart = $start + strlen('CVE-');
        $year = substr($text, $yearStart, 4);
        $dashAfterYear = $text[$yearStart + 4] ?? '';
        if (strlen($year) !== 4 || !ctype_digit($year) || $dashAfterYear !== '-') {
            return null;
        }

        $numberStart = $yearStart + strlen('2026-');
        $number = substr($text, $numberStart, strspn($text, '0123456789', $numberStart));
        $charAfterNumber = $text[$numberStart + strlen($number)] ?? ' ';
        if (strlen($number) < 4 || self::isWordChar($charAfterNumber)) {
            return null;
        }

        return "CVE-{$year}-{$number}";
    }

    /** "[UPDATE] [hoch] PHP: …" becomes "PHP: …". */
    private static function withoutLeadingTags(string $title): string
    {
        $rest = ltrim($title);
        while (str_starts_with($rest, '[')) {
            $tagEnd = mb_strpos($rest, ']');
            $tagLength = $tagEnd === false ? 0 : $tagEnd - 1;
            if ($tagLength < 1 || $tagLength > self::TITLE_TAG_MAX_LENGTH) {
                break;
            }
            $rest = ltrim(mb_substr($rest, $tagEnd + 1));
        }

        return trim($rest);
    }

    /** An ASCII letter, digit or underscore. */
    private static function isWordChar(string $char): bool
    {
        return ctype_alnum($char) || $char === '_';
    }

    /**
     * Splits at everything but letters, digits and "_", Unicode-aware, so "ü" is part of a
     * word. Invalid UTF-8 has no words.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/\W+/u', $text, flags: PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }
}
