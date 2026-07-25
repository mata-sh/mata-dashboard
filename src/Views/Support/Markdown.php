<?php

declare(strict_types=1);

namespace Mata\Views\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Output\RenderedContentInterface;

/**
 * Markdown parser and converter utility.
 *
 * Provides secure markdown-to-HTML conversion using GitHub Flavored Markdown:
 * - Safe markdown parsing with HTML stripping
 * - Security-focused configuration (no unsafe links)
 * - GFM support for README tables/task lists
 * - Documentation and README file processing
 * - Content sanitization and XSS prevention
 * - HTML output generation for web display
 */
class Markdown
{
    private const CONFIG = [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ];

    private string $markdown;

    /**
     * Initialize markdown parser with content.
     */
    public function __construct(string $markdown)
    {
        $this->markdown = $markdown;
    }

    /**
     * Convert markdown content to safe HTML string.
     */
    public static function toSafeHtml(string $markdown): string
    {
        return (string) self::converter()->convert($markdown);
    }

    /**
     * Convert markdown content to HTML.
     */
    public function convert(): RenderedContentInterface
    {
        return self::converter()->convert($this->markdown);
    }

    private static function converter(): GithubFlavoredMarkdownConverter
    {
        return new GithubFlavoredMarkdownConverter(self::CONFIG);
    }
}
