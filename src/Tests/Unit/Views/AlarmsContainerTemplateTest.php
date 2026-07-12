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
    public function testRendersAcknowledgementStatusAsSuccessBadge(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../../templates/partials/containers/alarms-container.html.twig');

        $this->assertSame(1, substr_count($template, 'title: "Acknowledged"'));
        $this->assertStringContainsString('field: "acknowledged_at"', $template);
        $this->assertStringContainsString('badge.className = "cve-badge cve-ok";', $template);
        $this->assertStringContainsString('badge.dataset.tippyContent = "Acknowledged by "', $template);
        $this->assertStringContainsString('<polyline points="20 6 9 17 4 12"></polyline>', $template);
    }

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
