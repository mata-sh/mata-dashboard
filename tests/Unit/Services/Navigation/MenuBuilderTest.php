<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Navigation;

use Mata\Http\Routing\Endpoints;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Utils\Tools;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Navigation\MenuBuilder
 *
 * @internal
 */
final class MenuBuilderTest extends TestCase
{
    public function testEnablesDatabasesMenuItem(): void
    {
        $endpoints = new Endpoints();
        $endpoints->register('databases_render', '/watch/databases');
        $tools = $this->createStub(Tools::class);
        $tools->method('icon')->willReturn('');

        $item = (new MenuBuilder($endpoints, $tools))->getMenuItem('databases');

        $this->assertNotNull($item);
        $this->assertTrue($item['enabled']);
        $this->assertSame('databases_render', $item['endpoint']);
        $this->assertSame('/watch/databases', $item['url']);
    }

    public function testMenuOverridesToggleItemVisibility(): void
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('icon')->willReturn('');

        // 'metrics' defaults to disabled, 'databases' defaults to enabled - flip both.
        $builder = new MenuBuilder(new Endpoints(), $tools, ['metrics' => true, 'databases' => false]);

        $this->assertTrue($builder->getMenuItem('metrics')['enabled']);
        $this->assertFalse($builder->getMenuItem('databases')['enabled']);
        // An item without an override keeps its built-in default.
        $this->assertTrue($builder->getMenuItem('events')['enabled']);
    }

    public function testMenuKeysConstantStaysInSyncWithBuiltMenu(): void
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('icon')->willReturn('');

        $builtKeys = array_column((new MenuBuilder(new Endpoints(), $tools))->buildDashboardMenu(), 'key');

        sort($builtKeys);
        $expected = MenuBuilder::MENU_KEYS;
        sort($expected);

        $this->assertSame(
            $expected,
            $builtKeys,
            'MenuBuilder::MENU_KEYS must match the keys emitted by buildDashboardMenu() so menu.json validation stays accurate'
        );
    }
}
