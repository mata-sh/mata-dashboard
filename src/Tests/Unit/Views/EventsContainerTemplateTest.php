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
    public function testFormatsEventDatetimesAsConciseUtcValues(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../../templates/partials/containers/events-container.html.twig');

        $this->assertStringContainsString('date.toISOString().slice(0, 16).replace("T", " ") + " UTC"', $template);
        $this->assertStringContainsString('isoValue += "Z";', $template);
    }
}
