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
    public function testUsesAnExternalInitializerForHtmxSwaps(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../../templates/partials/containers/alarms-container.html.twig');
        $script = (string) file_get_contents(__DIR__ . '/../../../../public/assets/js/alarms-table.js');

        $this->assertStringContainsString('data-alarms-url="{{ endpoints.alarms_json }}"', $template);
        $this->assertStringContainsString('data-acknowledge-url="{{ endpoints.alarms_ack }}"', $template);
        $this->assertStringNotContainsString('<script>', $template);
        $this->assertStringContainsString('htmx:beforeSwap', $script);
        $this->assertStringContainsString('htmx:afterSettle', $script);
        $this->assertStringContainsString('table.destroy()', $script);
        $this->assertStringContainsString('ajaxURL: alarmsUrl', $script);
    }

    public function testRendersAcknowledgementStatusAsSuccessBadge(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../../../public/assets/js/alarms-table.js');

        $this->assertSame(1, substr_count($script, "title: 'Acknowledged'"));
        $this->assertStringContainsString("field: 'acknowledged_at'", $script);
        $this->assertStringContainsString("return '<button type=\"button\" class=\"button is-small alarm-ack-btn\">ACK</button>';", $script);
        $this->assertStringNotContainsString("field: 'actions'", $script);
        $this->assertStringContainsString("badge.className = 'cve-badge cve-ok';", $script);
        $this->assertStringContainsString("badge.dataset.tippyContent = 'Acknowledged by '", $script);
        $this->assertStringContainsString('<polyline points="20 6 9 17 4 12"></polyline>', $script);
    }

    public function testRendersOneEscapedDeliveriesColumn(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../../../public/assets/js/alarms-table.js');

        $this->assertSame(1, substr_count($script, "title: 'Deliveries'"));
        $this->assertStringNotContainsString("title: 'Channel'", $script);
        $this->assertStringNotContainsString("title: 'Status'", $script);
        $this->assertStringContainsString("return '—';", $script);
        $this->assertStringContainsString("tag.textContent = channel + ': ' + label;", $script);
        $this->assertLessThan(
            strpos($script, "title: 'Context'"),
            strpos($script, "title: 'Deliveries'"),
        );

        foreach (['sent', 'pending', 'processing', 'retry', 'failed', 'throttled', 'discarded'] as $status) {
            $this->assertStringContainsString($status . ": '", $script);
        }
    }
}
