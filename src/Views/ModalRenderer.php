<?php

declare(strict_types=1);

namespace Mata\Views;

use Exception;
use Mata\Utils\Markdown;

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
    /**
     * Build template data for README modal.
     *
     * @param string                                 $markdownContent Raw markdown content
     * @param string                                 $server          Server name
     * @param string                                 $app             Application name
     * @param int                                    $cacheAge        Cache age in seconds
     * @param int                                    $cacheTtl        Cache ttl in seconds
     * @param array<string,mixed>                    $endpoints       Pre-built endpoint URLs
     * @param null|array{code:string,message:string} $notice          Contextual node notice
     *
     * @return array<string,mixed> Template data array
     */
    public function buildReadmeModalData(
        string $markdownContent,
        string $server,
        string $app,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints,
        ?array $notice = null
    ): array {
        $convertedContent = $this->renderReadmeContent($markdownContent);

        return [
            'content' => [
                'modal_content' => $convertedContent,
            ],
            'notice' => $notice,
            'app' => [
                'server' => $server,
                'name' => $app,
            ],
            'endpoints' => $endpoints,
            'icons' => $this->getModalIcons(),
            'cache' => [
                'age' => $this->tools->formatDuration($cacheAge),
                'ttl' => $this->tools->formatDuration($cacheTtl),
            ],
        ];
    }

    /**
     * Build template data for logs modal.
     *
     * @param string              $server    Server name
     * @param string              $app       Application name
     * @param int                 $cacheAge  Cache age in seconds
     * @param int                 $cacheTtl  Cache TTL in seconds
     * @param array<string,mixed> $endpoints Pre-built endpoint URLs
     *
     * @return array<string,mixed> Template data array
     */
    public function buildLogsModalData(
        string $server,
        string $app,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints
    ): array {
        $logsTableHtml = $this->renderLogsTable($server, $app, $endpoints);

        return [
            'content' => [
                'modal_content' => $logsTableHtml,
            ],
            'app' => [
                'server' => $server,
                'name' => $app,
            ],
            'endpoints' => $endpoints,
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

            $readmeHtml = Markdown::toSafeHtml($sanitized['content']);

            $htmlContent = <<< HTML
                <div class="readme-markdown-container">
                    {$readmeHtml}
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
     * Render logs table HTML.
     *
     * @param string $server    Server name
     * @param string $app       Application name
     * @param array  $endpoints Endpoint URLs for the table
     *
     * @return string HTML table content
     */
    public function renderLogsTable(string $server, string $app, array $endpoints = []): string
    {
        $data = [
            'app' => [
                'server' => $server,
                'name' => $app,
            ],
            'endpoints' => $endpoints,
        ];

        try {
            return $this->twig->render('partials/components/logs-table.html.twig', $data);
        } catch (Exception $e) {
            $this->logError($e->getMessage(), 'LogsTable', [
                'server' => $server,
                'app' => $app,
            ]);

            return '<p>Error rendering logs table</p>';
        }
    }

    /**
     * Render complete README modal.
     *
     * @param string                                 $markdownContent Raw markdown content
     * @param string                                 $server          Server name
     * @param string                                 $app             Application name
     * @param int                                    $cacheAge        Cache age in seconds
     * @param int                                    $cacheTtl        Cache TTL in seconds
     * @param array<string,mixed>                    $endpoints       Pre-built endpoint URLs
     * @param null|array{code:string,message:string} $notice          Contextual node notice
     *
     * @return string Rendered modal HTML
     */
    public function renderReadmeModal(
        string $markdownContent,
        string $server,
        string $app,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints,
        ?array $notice = null
    ): string {
        $data = $this->buildReadmeModalData($markdownContent, $server, $app, $cacheAge, $cacheTtl, $endpoints, $notice);

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
     * Render complete logs modal.
     *
     * @param string              $server    Server name
     * @param string              $app       Application name
     * @param int                 $cacheAge  Cache age in seconds
     * @param int                 $cacheTtl  Cache TTL in seconds
     * @param array<string,mixed> $endpoints Pre-built endpoint URLs
     *
     * @return string Rendered modal HTML
     */
    public function renderLogsModal(
        string $server,
        string $app,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints
    ): string {
        $data = $this->buildLogsModalData($server, $app, $cacheAge, $cacheTtl, $endpoints);

        try {
            return $this->twig->render('partials/modals/logs-modal.html.twig', $data);
        } catch (Exception $e) {
            $this->logError($e->getMessage(), 'LogsModal', [
                'server' => $server,
                'app' => $app,
            ]);

            return '<div class="notification is-danger">Error rendering logs modal</div>';
        }
    }

    /**
     * Build template data for an application's log-file metadata modal.
     *
     * @param array<mixed>        $logFiles  Log metadata keyed by file path
     * @param array<string,mixed> $endpoints Pre-built endpoint URLs
     *
     * @return array<string,mixed> Template data array
     */
    public function buildAppLogFilesModalData(
        array $logFiles,
        string $server,
        string $app,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints
    ): array {
        $entries = [];

        foreach ($logFiles as $path => $metadata) {
            if (!is_array($metadata)) {
                continue;
            }

            $size = is_numeric($metadata['size'] ?? null)
                ? $this->tools->formatBytes((int) $metadata['size'])
                : null;
            $lineCount = $metadata['lines'] ?? null;
            $lastModified = $metadata['last_modified'] ?? null;

            $entries[] = [
                'path' => is_string($path) ? $path : null,
                'size' => $size,
                'line_count' => is_numeric($lineCount) ? number_format((int) $lineCount) : null,
                'last_modified' => is_scalar($lastModified) ? (string) $lastModified : null,
            ];
        }

        return [
            'app' => [
                'server' => $server,
                'name' => $app,
            ],
            'entries' => $entries,
            'endpoints' => $endpoints,
            'icons' => $this->getModalIcons(),
            'cache' => [
                'age' => $this->tools->formatDuration($cacheAge),
                'ttl' => $this->tools->formatDuration($cacheTtl),
            ],
        ];
    }

    /**
     * Render an application's log-file metadata modal.
     *
     * @param array<mixed>        $logFiles  Log metadata keyed by file path
     * @param array<string,mixed> $endpoints Pre-built endpoint URLs
     */
    public function renderAppLogFilesModal(
        array $logFiles,
        string $server,
        string $app,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints
    ): string {
        $data = $this->buildAppLogFilesModalData(
            $logFiles,
            $server,
            $app,
            $cacheAge,
            $cacheTtl,
            $endpoints
        );

        try {
            return $this->twig->render('partials/modals/log-files-modal.html.twig', $data);
        } catch (Exception $e) {
            $this->logError($e->getMessage(), 'AppLogFilesModal', [
                'server' => $server,
                'app' => $app,
            ]);

            return '<div class="notification is-danger">Error rendering application log files modal</div>';
        }
    }

    /**
     * Build template data for server info modal.
     *
     * @param array<string,mixed> $serverInfo Server information data
     * @param string              $server     Server name
     * @param int                 $cacheAge   Cache age in seconds
     * @param int                 $cacheTtl   Cache TTL in seconds
     * @param array<string,mixed> $endpoints  Pre-built endpoint URLs
     *
     * @return array<string,mixed> Template data array
     */
    public function buildServerInfoModalData(
        array $serverInfo,
        string $server,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints
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
            'endpoints' => $endpoints,
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
     * @param array<string,mixed> $endpoints  Pre-built endpoint URLs
     *
     * @return string Rendered modal HTML
     */
    public function renderServerInfoModal(
        array $serverInfo,
        string $server,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints
    ): string {
        $data = $this->buildServerInfoModalData($serverInfo, $server, $cacheAge, $cacheTtl, $endpoints);

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
     * Build template data for a database process-list modal.
     *
     * Values remain plain strings so Twig escapes every node-supplied cell.
     *
     * @param array<int,array<string,mixed>> $processList Process-list rows
     * @param array<string,mixed>            $endpoints   Pre-built endpoint URLs
     *
     * @return array<string,mixed> Template data array
     */
    public function buildDatabaseProcessesModalData(
        array $processList,
        string $server,
        string $database,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints
    ): array {
        $processes = [];

        foreach ($processList as $process) {
            if (!is_array($process)) {
                continue;
            }

            $row = [];
            foreach (['id', 'user', 'host', 'db', 'command', 'time', 'state', 'info'] as $field) {
                $value = $process[$field] ?? null;
                $row[$field] = is_scalar($value) ? (string) $value : null;
            }
            $processes[] = $row;
        }

        return [
            'server' => ['name' => $server],
            'database' => ['name' => $database],
            'processes' => $processes,
            'endpoints' => $endpoints,
            'icons' => $this->getModalIcons(),
            'cache' => [
                'age' => $this->tools->formatDuration($cacheAge),
                'ttl' => $this->tools->formatDuration($cacheTtl),
            ],
        ];
    }

    /**
     * Render a database process-list modal.
     *
     * @param array<int,array<string,mixed>> $processList Process-list rows
     * @param array<string,mixed>            $endpoints   Pre-built endpoint URLs
     */
    public function renderDatabaseProcessesModal(
        array $processList,
        string $server,
        string $database,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints
    ): string {
        $data = $this->buildDatabaseProcessesModalData(
            $processList,
            $server,
            $database,
            $cacheAge,
            $cacheTtl,
            $endpoints
        );

        try {
            return $this->twig->render('partials/modals/database-processes-modal.html.twig', $data);
        } catch (Exception $e) {
            $this->logError($e->getMessage(), 'DatabaseProcessesModal', [
                'server' => $server,
                'database' => $database,
            ]);

            return '<div class="notification is-danger">Error rendering database processes modal</div>';
        }
    }

    /**
     * Build template data for server cronjobs modal.
     *
     * @param array<int,array<string,mixed>> $cronjobs  Cronjob entries
     * @param string                         $server    Server name
     * @param int                            $cacheAge  Cache age in seconds
     * @param int                            $cacheTtl  Cache TTL in seconds
     * @param array<string,mixed>            $endpoints Pre-built endpoint URLs
     *
     * @return array<string,mixed> Template data array
     */
    public function buildServerCronjobsModalData(
        array $cronjobs,
        string $server,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints
    ): array {
        $entries = [];
        foreach ($cronjobs as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $exists = (bool) ($entry['exists'] ?? false);
            $stale = (bool) ($entry['stale'] ?? false);
            $ageSeconds = $entry['age_seconds'] ?? null;
            $size = $entry['size'] ?? null;
            $contentTail = $entry['content_tail'] ?? [];

            $entries[] = [
                'path' => $entry['path'] ?? null,
                'status' => !$exists ? 'missing' : ($stale ? 'stale' : 'ok'),
                'mtime' => $entry['mtime'] ?? null,
                'age' => $ageSeconds === null ? null : $this->tools->formatDuration((int) $ageSeconds),
                'size' => $size === null ? null : $this->tools->formatBytes((int) $size),
                'content_tail' => is_array($contentTail)
                    ? array_values(array_filter($contentTail, static fn ($line): bool => is_string($line)))
                    : [],
            ];
        }

        return [
            'server' => [
                'name' => $server,
            ],
            'entries' => $entries,
            'endpoints' => $endpoints,
            'icons' => $this->getModalIcons(),
            'cache' => [
                'age' => $this->tools->formatDuration($cacheAge),
                'ttl' => $this->tools->formatDuration($cacheTtl),
            ],
        ];
    }

    /**
     * Render complete server cronjobs modal.
     *
     * @param array<int,array<string,mixed>> $cronjobs  Cronjob entries
     * @param string                         $server    Server name
     * @param int                            $cacheAge  Cache age in seconds
     * @param int                            $cacheTtl  Cache TTL in seconds
     * @param array<string,mixed>            $endpoints Pre-built endpoint URLs
     *
     * @return string Rendered modal HTML
     */
    public function renderServerCronjobsModal(
        array $cronjobs,
        string $server,
        int $cacheAge,
        int $cacheTtl,
        array $endpoints
    ): string {
        $data = $this->buildServerCronjobsModalData($cronjobs, $server, $cacheAge, $cacheTtl, $endpoints);

        try {
            return $this->twig->render('partials/modals/cronjobs-modal.html.twig', $data);
        } catch (Exception $e) {
            $this->logError($e->getMessage(), 'ServerCronjobsModal', [
                'server' => $server,
            ]);

            return '<div class="notification is-danger">Error rendering server cronjobs modal</div>';
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
