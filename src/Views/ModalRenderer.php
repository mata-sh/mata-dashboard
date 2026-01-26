<?php

declare(strict_types=1);

namespace Mata\Views;

use Exception;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Utils\Tools;
use Monolog\Logger;
use Twig\Environment;

/**
 * Modal rendering service.
 *
 * Handles modal-related view rendering:
 * - README modal content generation
 * - Error log modal content generation
 * - Markdown content conversion
 * - Modal template data preparation
 */
class ModalRenderer extends BaseRenderer
{
    private GithubFlavoredMarkdownConverter $markdownConverter;

    public function __construct(
        Environment $twig,
        Logger $logger,
        Tools $tools,
        App $app,
        Endpoints $endpoints
    ) {
        parent::__construct($twig, $logger, $tools, $app, $endpoints);

        $this->markdownConverter = new GithubFlavoredMarkdownConverter([
            // 'html_input' => 'strip',
            // 'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Build template data for README modal.
     *
     * @param string $markdownContent Raw markdown content
     * @param string $server          Server name
     * @param string $app             Application name
     * @param int    $cacheAge        Cache age in seconds
     * @param int    $cacheTtl        Cache ttl in seconds
     *
     * @return array<string,mixed> Template data array
     */
    public function buildReadmeModalData(
        string $markdownContent,
        string $server,
        string $app,
        int $cacheAge,
        int $cacheTtl
    ): array {
        $convertedContent = $this->renderReadmeContent($markdownContent);

        return [
            'content' => [
                'modal_content' => $convertedContent,
            ],
            'app' => [
                'server' => $server,
                'name' => $app,
            ],
            'icons' => $this->getModalIcons(),
            'cache' => [
                'age' => $this->tools->formatDuration($cacheAge),
                'ttl' => $this->tools->formatDuration($cacheTtl),
            ],
        ];
    }

    /**
     * Build template data for error log modal.
     *
     * @param string $server   Server name
     * @param string $app      Application name
     * @param int    $cacheAge Cache age in seconds
     * @param int    $cacheTtl Cache TTL in seconds
     *
     * @return array<string,mixed> Template data array
     */
    public function buildErrorlogModalData(
        string $server,
        string $app,
        int $cacheAge,
        int $cacheTtl
    ): array {
        $errorLogTableHtml = $this->renderErrorlogTable($server, $app);

        return [
            'content' => [
                'modal_content' => $errorLogTableHtml,
            ],
            'app' => [
                'server' => $server,
                'name' => $app,
            ],
            'endpoints' => [
                'errorlog_json' => $this->endpoints->url('app_errorlog_json', [
                    'server' => $server,
                    'app' => $app,
                ]),
            ],
            'icons' => $this->getModalIcons(),
            'cache' => [
                'age' => $this->tools->formatDuration($cacheAge),
                'ttl' => $this->tools->formatDuration($cacheTtl),
            ],
        ];
    }

    /**
     * Sanitize markdown content for web display.
     *
     * Replaces image references with informative placeholders since
     * relative image paths won't resolve correctly in web context.
     * Also prepends an informational notice if any images were filtered out.
     *
     * @param string $markdownContent Raw markdown content
     *
     * @return array{content: string, image_count: int} Sanitized markdown content & image count
     */
    private function sanitizeMarkdownForWeb(string $markdownContent): array
    {
        $imageFound = false;
        $references = [];

        // ────────────────────────────────────────────────
        // Collect reference definitions
        // ────────────────────────────────────────────────
        preg_match_all(
            '/^\s*\[([^\]]+)\]:\s*(?!https?:\/\/)([^\s]+?\.(?:png|jpe?g|gif|svg|webp|avif))(?=\s|$)/im',
            $markdownContent,
            $referenceMatches,
            PREG_SET_ORDER
        );

        $i = 0;
        foreach ($referenceMatches as $match) {
            ++$i;
            $references[strtolower(trim($match[1]))] = trim($match[2]);
        }

        // ────────────────────────────────────────────────
        // Inline markdown images ![alt](path)
        // ────────────────────────────────────────────────
        $markdownPattern = '/!\[([^\]]*)\]\(\s*(?!https?:\/\/)([^) "\']+\.(?:png|jpe?g|gif|svg|webp|avif))[^)]*\)/i';
        $markdownContent = preg_replace_callback($markdownPattern, function ($matches) use (&$imageFound) {
            $imageFound = true;
            $altText = $matches[1] !== '' ? trim($matches[1]) : 'Image';
            $imagePath = trim($matches[2]);

            return "📷 **Image:** {$altText} (`{$imagePath}`)";
        }, $markdownContent);

        // ────────────────────────────────────────────────
        // Reference-style images ![alt][id]
        // ────────────────────────────────────────────────
        $refPattern = '/!\[([^\]]*)\]\[([^\]]*)\]/';
        $markdownContent = preg_replace_callback($refPattern, function ($matches) use ($references, &$imageFound) {
            $altText = $matches[1] !== '' ? trim($matches[1]) : 'Image';
            $refId = strtolower(trim($matches[2]));
            if (isset($references[$refId])) {
                $imageFound = true;
                $imagePath = $references[$refId];

                return "📷 **Image:** {$altText} (`{$imagePath}`)";
            }

            return $matches[0]; // leave untouched if ref not found
        }, $markdownContent);

        // ────────────────────────────────────────────────
        // HTML <img> tags
        // ────────────────────────────────────────────────
        $htmlPattern = '/<img\s+[^>]*src=["\'](?!https?:\/\/)([^"\']+\.(?:png|jpe?g|gif|svg|webp|avif))["\'][^>]*>/i';
        $markdownContent = preg_replace_callback($htmlPattern, function ($matches) use (&$imageFound) {
            $imageFound = true;
            $imagePath = trim($matches[1]);

            return "📷 **HTML Image:** (`{$imagePath}`)";
        }, $markdownContent);

        return [
            'content' => $markdownContent,
            'image_count' => $i,
        ];
    }

    /**
     * Convert markdown content to sanitized HTML.
     *
     * @param string $markdownContent Raw markdown content
     *
     * @return string Converted HTML content
     */
    public function renderReadmeContent(string $markdownContent): string
    {
        try {
            // Ensure we always have valid content
            if (trim($markdownContent) === '') {
                return 'No README.md content found.';
            }

            // Sanitize markdown and capture image info
            $sanitized = $this->sanitizeMarkdownForWeb($markdownContent);

            $htmlContent = <<< HTML
                <div class"readme-markdown-container">
                    {$this->markdownConverter->convert($sanitized['content'])}
                </div>
            HTML;

            // Optionally prepend image notice if replacements occurred
            if (!empty($sanitized['image_count'])) {
                $notice = $this->buildImageNotice($sanitized['image_count']);
                $htmlContent = $notice . "\n" . $htmlContent;
            }

            return $htmlContent;
        } catch (Exception $e) {
            $this->logError($e->getMessage(), 'MarkdownConversion', [
                'content_length' => strlen($markdownContent),
            ]);

            return '<p><strong>Error:</strong> Failed to render markdown content.</p>';
        }
    }

    /**
     * Build an HTML notice for replaced image references.
     *
     * @param int $imageCount Number of replaced image references
     *
     * @return string HTML block
     */
    protected function buildImageNotice(int $imageCount): string
    {
        return <<<HTML
            <div class="readme-markdown-notice">
                📷 image references were replaced with text placeholders ({$imageCount}).
            </div>
        HTML;
    }

    /**
     * Render error log table HTML.
     *
     * @param string $server Server name
     * @param string $app    Application name
     *
     * @return string HTML table content
     */
    public function renderErrorlogTable(string $server, string $app): string
    {
        $data = [
            'app' => [
                'server' => $server,
                'name' => $app,
            ],
        ];

        try {
            return $this->twig->render('partials/components/errorlog-table.html.twig', $data);
        } catch (Exception $e) {
            $this->logError($e->getMessage(), 'ErrorlogTable', [
                'server' => $server,
                'app' => $app,
            ]);

            return '<p>Error rendering error log table</p>';
        }
    }

    /**
     * Render complete README modal.
     *
     * @param string $markdownContent Raw markdown content
     * @param string $server          Server name
     * @param string $app             Application name
     * @param int    $cacheAge        Cache age in seconds
     *
     * @return string Rendered modal HTML
     */
    public function renderReadmeModal(
        string $markdownContent,
        string $server,
        string $app,
        int $cacheAge,
        int $cacheTtl
    ): string {
        $data = $this->buildReadmeModalData($markdownContent, $server, $app, $cacheAge, $cacheTtl);

        try {
            return $this->twig->render('partials/modals/readme-modal.html.twig', $data);
        } catch (Exception $e) {
            $this->logError($e->getMessage(), 'ReadmeModal', [
                'server' => $server,
                'app' => $app,
            ]);

            return '<div class="notification is-danger">Error rendering README modal</div>';
        }
    }

    /**
     * Render complete error log modal.
     *
     * @param string $server   Server name
     * @param string $app      Application name
     * @param int    $cacheAge Cache age in seconds
     * @param int    $cacheTtl Cache TTL in seconds
     *
     * @return string Rendered modal HTML
     */
    public function renderErrorlogModal(
        string $server,
        string $app,
        int $cacheAge,
        int $cacheTtl
    ): string {
        $data = $this->buildErrorlogModalData($server, $app, $cacheAge, $cacheTtl);

        try {
            return $this->twig->render('partials/modals/errorlog-modal.html.twig', $data);
        } catch (Exception $e) {
            $this->logError($e->getMessage(), 'ErrorlogModal', [
                'server' => $server,
                'app' => $app,
            ]);

            return '<div class="notification is-danger">Error rendering error log modal</div>';
        }
    }

    /**
     * Build template data for server info modal.
     *
     * @param array<string,mixed> $serverInfo Server information data
     * @param string              $server     Server name
     * @param int                 $cacheAge   Cache age in seconds
     * @param int                 $cacheTtl   Cache TTL in seconds
     *
     * @return array<string,mixed> Template data array
     */
    public function buildServerInfoModalData(
        array $serverInfo,
        string $server,
        int $cacheAge,
        int $cacheTtl
    ): array {
        return [
            'server' => [
                'name' => $server,
                'os_name' => $serverInfo['os_name'] ?? 'N/A',
                'os_version' => $serverInfo['os_version'] ?? 'N/A',
                'kernel_version' => $serverInfo['kernel_version'] ?? 'N/A',
                'package_count' => $serverInfo['package_count'] ?? 0,
                'php_version' => $serverInfo['php_version'] ?? 'N/A',
                'php_extension_count' => $serverInfo['php_extension_count'] ?? 0,
                'php_extensions' => $serverInfo['php_extensions'] ?? [],
                'architecture' => $serverInfo['architecture'] ?? 'N/A',
                'hostname' => $serverInfo['hostname'] ?? 'N/A',
            ],
            'icons' => $this->getModalIcons(),
            'cache' => [
                'age' => $this->tools->formatDuration($cacheAge),
                'ttl' => $this->tools->formatDuration($cacheTtl),
            ],
        ];
    }

    /**
     * Render complete server info modal.
     *
     * @param array<string,mixed> $serverInfo Server information data
     * @param string              $server     Server name
     * @param int                 $cacheAge   Cache age in seconds
     * @param int                 $cacheTtl   Cache TTL in seconds
     *
     * @return string Rendered modal HTML
     */
    public function renderServerInfoModal(
        array $serverInfo,
        string $server,
        int $cacheAge,
        int $cacheTtl
    ): string {
        $data = $this->buildServerInfoModalData($serverInfo, $server, $cacheAge, $cacheTtl);

        try {
            return $this->twig->render('partials/modals/server-info-modal.html.twig', $data);
        } catch (Exception $e) {
            $this->logError($e->getMessage(), 'ServerInfoModal', [
                'server' => $server,
            ]);

            return '<div class="notification is-danger">Error rendering server info modal</div>';
        }
    }

    /**
     * Get modal-specific icons.
     *
     * @return array<string,string> Icon name to HTML mapping
     */
    private function getModalIcons(): array
    {
        return [
            'close' => $this->tools->icon('x-circle', 12),
            'refresh' => $this->tools->icon('refresh-cw', 12),
        ];
    }
}
