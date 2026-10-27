<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use Mata\Views\Support\UiTheme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @internal
 *
 * @coversNothing
 */
final class UiThemeTest extends TestCase
{
    #[DataProvider('cookieValues')]
    public function testRendersOnlyKnownThemesFromTheCookie(?string $cookie, string $expected): void
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../../../templates'), ['autoescape' => 'html']);
        $twig->addGlobal('theme', UiTheme::fromCookie($cookie)->value);

        $html = $twig->render('pages/error.html.twig', ['error' => ['message' => 'x']]);

        $this->assertStringContainsString("<html lang=\"en\" data-theme=\"{$expected}\">", $html);
        $this->assertStringContainsString("<meta name=\"color-scheme\" content=\"{$expected}\">", $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    /** @return iterable<string,array{?string,string}> */
    public static function cookieValues(): iterable
    {
        yield 'no cookie' => [null, 'dark'];

        yield 'light' => ['light', 'light'];

        yield 'dark' => ['dark', 'dark'];

        yield 'unknown theme' => ['solarized', 'dark'];

        yield 'wrong case' => ['Light', 'dark'];

        yield 'attribute injection' => ['light" onload="alert(1)', 'dark'];
    }
}
