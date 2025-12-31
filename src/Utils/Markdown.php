<?php

declare(strict_types=1);

namespace Watcher\Utils;

use League\CommonMark\CommonMarkConverter;

/**
 * Markdown parser and converter utility.
 *
 * Provides secure markdown-to-HTML conversion using CommonMark:
 * - Safe markdown parsing with HTML stripping
 * - Security-focused configuration (no unsafe links)
 * - CommonMark specification compliance
 * - Documentation and README file processing
 * - Content sanitization and XSS prevention
 * - HTML output generation for web display
 */
class Markdown
{
    private string $markdown;

    /**
     * Initialize markdown parser with content.
     */
    public function __construct(string $markdown)
    {
        $this->markdown = $markdown;
    }

    /**
     * Convert markdown content to HTML.
     *
     * Uses CommonMark converter with security-focused configuration.
     *
     * @return \League\CommonMark\Output\RenderedContentInterface Converted HTML content
     */
    public function convert()
    {
        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $converter->convert($this->markdown);
    }
}
