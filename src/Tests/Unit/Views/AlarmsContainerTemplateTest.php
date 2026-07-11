<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AlarmsContainerTemplateTest extends TestCase
{
    public function testRendersOneEscapedDeliveriesColumn(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../../templates/partials/containers/alarms-container.html.twig');

        $this->assertSame(1, substr_count($template, 'title: "Deliveries"'));
        $this->assertStringNotContainsString('title: "Channel"', $template);
        $this->assertStringNotContainsString('title: "Status"', $template);
        $this->assertStringContainsString('return "—";', $template);
        $this->assertStringContainsString('element.textContent = String(value || "");', $template);
        $this->assertStringContainsString('escapeHtml(channel)', $template);
        $this->assertStringContainsString('escapeHtml(label)', $template);

        foreach (['sent', 'pending', 'processing', 'retry', 'failed', 'throttled', 'discarded'] as $status) {
            $this->assertStringContainsString($status . ': "', $template);
        }
    }
}
