<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class EventsContainerTemplateTest extends TestCase
{
    public function testUsesAnExternalInitializerForHtmxSwaps(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../../templates/partials/containers/events-container.html.twig');
        $script = (string) file_get_contents(__DIR__ . '/../../../../public/assets/js/events-table.js');

        $this->assertStringContainsString('data-events-url="{{ endpoints.events_json }}"', $template);
        $this->assertStringNotContainsString('<script>', $template);
        $this->assertStringContainsString("htmx:beforeSwap", $script);
        $this->assertStringContainsString("htmx:afterSwap", $script);
        $this->assertStringContainsString('table.destroy()', $script);
        $this->assertStringContainsString("ajaxURL: eventsUrl", $script);
    }

    public function testFormatsEventDatetimesAsConciseUtcValues(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../../../public/assets/js/events-table.js');

        $this->assertStringContainsString("date.toISOString().slice(0, 16).replace('T', ' ') + ' UTC'", $script);
        $this->assertStringContainsString("isoValue += 'Z';", $script);
    }
}
