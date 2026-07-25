<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use Mata\Views\Support\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \Mata\Views\Support\Markdown
 */
class MarkdownTest extends TestCase
{
    public function testToSafeHtmlStripsRawHtmlAndUnsafeLinks(): void
    {
        $html = Markdown::toSafeHtml(<<<'MARKDOWN'
            # Test
            <script>alert(1)</script>
            <img src=x onerror=alert(1)>
            [bad](javascript:alert(1))
            MARKDOWN);

        $this->assertStringContainsString('<h1>Test</h1>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror=', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function testInstanceConverterUsesSameSafeConfig(): void
    {
        $html = (string) (new Markdown(<<<'MARKDOWN'
            <script>alert(1)</script>
            <img src=x onerror=alert(1)>
            [bad](javascript:alert(1))
            MARKDOWN))->convert();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror=', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function testToSafeHtmlKeepsGithubFlavoredMarkdownTables(): void
    {
        $html = Markdown::toSafeHtml(<<<'MARKDOWN'
            | Name | Status |
            | --- | --- |
            | App | OK |
            MARKDOWN);

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<td>App</td>', $html);
        $this->assertStringContainsString('<td>OK</td>', $html);
    }
}
