<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use Psr\Log\LoggerInterface;

/**
 * Resolves the logo and favicon paths for the web UI. Seeds missing files in public/branding/
 * from the bundled defaults, so instance-specific assets placed there take precedence.
 */
final readonly class BrandingResolver
{
    public function __construct(
        private string $brandingDir,
        private string $brandingDefaultsDir,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{logo: string, logo_topbar_dark: string, logo_topbar_light: string, favicon: string, favicon_type: string}
     */
    public function resolve(): array
    {
        $brandingAssets = [];
        foreach (['logo' => 'logo.png', 'logo_topbar_dark' => 'logo_topbar_dark.png', 'logo_topbar_light' => 'logo_topbar_light.png'] as $key => $file) {
            if (!file_exists($this->brandingDir . '/' . $file) && is_file($this->brandingDefaultsDir . '/' . $file)) {
                if (!is_dir($this->brandingDir)) {
                    @mkdir($this->brandingDir, 0755, true);
                }
                if (!@copy($this->brandingDefaultsDir . '/' . $file, $this->brandingDir . '/' . $file)) {
                    $this->logger->warning('Could not seed default branding file: public/branding/' . $file);
                }
            }
            $brandingAssets[$key] = file_exists($this->brandingDir . '/' . $file)
                ? 'branding/' . $file
                : 'assets/branding/' . $file;
        }

        $favicon = ['path' => 'assets/images/favicon.png', 'type' => 'image/png'];
        foreach (['favicon.svg' => 'image/svg+xml', 'favicon.png' => 'image/png'] as $file => $type) {
            if (is_file($this->brandingDir . '/' . $file)) {
                $favicon = ['path' => 'branding/' . $file, 'type' => $type];

                break;
            }

            if (is_file($this->brandingDefaultsDir . '/' . $file)) {
                if (!is_dir($this->brandingDir)) {
                    @mkdir($this->brandingDir, 0755, true);
                }
                if (@copy($this->brandingDefaultsDir . '/' . $file, $this->brandingDir . '/' . $file)) {
                    $favicon = ['path' => 'branding/' . $file, 'type' => $type];
                } else {
                    $this->logger->warning('Could not seed default branding file: public/branding/' . $file);
                    $favicon = ['path' => 'assets/branding/' . $file, 'type' => $type];
                }

                break;
            }
        }

        return [
            'logo' => $brandingAssets['logo'],
            'logo_topbar_dark' => $brandingAssets['logo_topbar_dark'],
            'logo_topbar_light' => $brandingAssets['logo_topbar_light'],
            'favicon' => $favicon['path'],
            'favicon_type' => $favicon['type'],
        ];
    }
}
