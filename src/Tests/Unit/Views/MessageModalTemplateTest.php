<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class MessageModalTemplateTest extends TestCase
{
    public function testProvidesOneAccessibleClientSideMessageDialog(): void
    {
        $layout = (string) file_get_contents(__DIR__ . '/../../../../templates/layouts/main.html.twig');
        $head = (string) file_get_contents(__DIR__ . '/../../../../templates/partials/structure/head.html.twig');

        $this->assertSame(1, substr_count($layout, 'id="message-modal"'));
        $this->assertStringContainsString('role="dialog"', $layout);
        $this->assertStringContainsString('aria-modal="true"', $layout);
        $this->assertStringContainsString('data-message-modal-close', $layout);
        $this->assertStringContainsString('assets/js/message-modal.js', $head);
    }

    public function testUsesTextContentForTheFullMessageAndSharedTableTrigger(): void
    {
        $modalScript = (string) file_get_contents(__DIR__ . '/../../../../public/assets/js/message-modal.js');

        $this->assertStringContainsString('messageElement.textContent = message === null || message === undefined ? \'\' : String(message);', $modalScript);
        $this->assertStringNotContainsString('innerHTML', $modalScript);
        $this->assertStringNotContainsString('fetch(', $modalScript);

        foreach (['events-table.js', 'alarms-table.js'] as $filename) {
            $script = (string) file_get_contents(__DIR__ . '/../../../../public/assets/js/' . $filename);

            $this->assertStringContainsString("Array.from(message).length > 50", $script);
            $this->assertStringContainsString("window.mataOpenMessageModal(message", $script);
            $this->assertStringNotContainsString('toggleMessage', $script);
        }
    }
}
