<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Bootstrap;

use Mata\Bootstrap\BrandingResolver;
use Mata\Tests\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @internal
 *
 * @covers \Mata\Bootstrap\BrandingResolver
 */
final class BrandingResolverTest extends TestCase
{
    private string $root;
    private string $brandingDir;
    private string $defaultsDir;

    protected function setUp(): void
    {
        $this->root = $this->createTempDirectory();
        $this->brandingDir = $this->root . '/branding';
        $this->defaultsDir = $this->root . '/defaults';
        mkdir($this->defaultsDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles([$this->root]);
    }

    public function testCustomBrandingFilesWin(): void
    {
        mkdir($this->brandingDir, 0777, true);
        file_put_contents($this->brandingDir . '/logo.png', 'custom logo');
        file_put_contents($this->brandingDir . '/favicon.png', 'custom favicon');
        file_put_contents($this->defaultsDir . '/logo.png', 'default logo');
        file_put_contents($this->defaultsDir . '/favicon.png', 'default favicon');

        $assets = (new BrandingResolver($this->brandingDir, $this->defaultsDir, new NullLogger()))->resolve();

        self::assertSame('branding/logo.png', $assets['logo']);
        self::assertSame('branding/favicon.png', $assets['favicon']);
        self::assertSame('image/png', $assets['favicon_type']);
        self::assertSame('custom logo', file_get_contents($this->brandingDir . '/logo.png'));
        self::assertSame('custom favicon', file_get_contents($this->brandingDir . '/favicon.png'));
    }

    public function testDefaultFilesAreSeededAndUsed(): void
    {
        file_put_contents($this->defaultsDir . '/logo.png', 'default logo');
        file_put_contents($this->defaultsDir . '/logo_topbar_dark.png', 'default topbar dark');
        file_put_contents($this->defaultsDir . '/logo_topbar_light.png', 'default topbar light');
        file_put_contents($this->defaultsDir . '/favicon.svg', 'default favicon');

        $assets = (new BrandingResolver($this->brandingDir, $this->defaultsDir, new NullLogger()))->resolve();

        self::assertSame('branding/logo.png', $assets['logo']);
        self::assertSame('branding/logo_topbar_dark.png', $assets['logo_topbar_dark']);
        self::assertSame('branding/logo_topbar_light.png', $assets['logo_topbar_light']);
        self::assertSame('branding/favicon.svg', $assets['favicon']);
        self::assertSame('image/svg+xml', $assets['favicon_type']);
        self::assertSame('default favicon', file_get_contents($this->brandingDir . '/favicon.svg'));
    }

    public function testFaviconCopyFailureUsesAssetPathAndStopsAtFirstCandidate(): void
    {
        file_put_contents($this->brandingDir, 'not a directory');
        file_put_contents($this->defaultsDir . '/favicon.svg', 'svg');
        file_put_contents($this->defaultsDir . '/favicon.png', 'png');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Could not seed default branding file: public/branding/favicon.svg');

        $assets = (new BrandingResolver($this->brandingDir, $this->defaultsDir, $logger))->resolve();

        self::assertSame('assets/branding/favicon.svg', $assets['favicon']);
        self::assertSame('image/svg+xml', $assets['favicon_type']);
    }

    public function testMissingFaviconsUseImagesDefault(): void
    {
        $assets = (new BrandingResolver($this->brandingDir, $this->defaultsDir, new NullLogger()))->resolve();

        self::assertSame('assets/images/favicon.png', $assets['favicon']);
        self::assertSame('image/png', $assets['favicon_type']);
    }
}
