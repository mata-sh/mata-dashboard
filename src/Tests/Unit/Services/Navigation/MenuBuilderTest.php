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
}
