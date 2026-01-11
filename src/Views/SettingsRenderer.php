<?php

declare(strict_types=1);

namespace Mata\Views;

/**
 * Settings renderer for configuration overview.
 *
 * Handles rendering of application settings and configuration data:
 * - Configuration file metadata and existence checks
 * - Server configuration summaries with masked secrets
 * - TTL (cache timeout) configuration display
 * - Threshold configuration for monitoring alerts
 */
class SettingsRenderer extends BaseRenderer
{
    /**
     * Render settings container with configuration overview.
     *
     * @param string                  $environment      Monitoring environment (prod, dev, etc.)
     * @param string                  $appEnvironment   Application environment
     * @param array<string,mixed>     $serversConfig    Servers configuration
     * @param array<string,float|int> $ttlConfig        TTL configuration
     * @param array<string,mixed>     $thresholdsConfig Thresholds configuration
     *
     * @return string Rendered HTML
     */
    public function renderContainer(
        string $environment,
        string $appEnvironment,
        array $serversConfig,
        array $ttlConfig,
        array $thresholdsConfig
    ): string {
        $configRoot = $this->app->paths->appPath . '/config';
        $environmentPath = $configRoot . '/' . $environment;

        return $this->twig->render('partials/containers/settings-container.html.twig', [
            'settings' => [
                'environment' => $environment,
                'app_environment' => $appEnvironment,
                'config_root' => $this->app->paths->toRelativePath($configRoot),
                'environment_path' => $this->app->paths->toRelativePath($environmentPath),
                'files' => $this->buildConfigFilesMetadata($environment, $configRoot),
                'servers' => $this->summarizeServersConfig($serversConfig),
                'ttl' => $this->summarizeTtlConfig($ttlConfig),
                'thresholds' => $this->summarizeThresholdsConfig($thresholdsConfig),
            ],
        ]);
    }

    // ────────────────────────────────────────────────
    // Configuration File Metadata
    // ────────────────────────────────────────────────

    /**
     * Build metadata about configuration files for display.
     *
     * @param string $environment Environment name (prod, dev, etc.)
     * @param string $configRoot  Configuration root directory path
     *
     * @return array<int,array<string,mixed>> Configuration files metadata
     */
    private function buildConfigFilesMetadata(string $environment, string $configRoot): array
    {
        $files = [
            [
                'name' => 'servers.json',
                'description' => 'Defines monitored servers and their API credentials.',
            ],
            [
                'name' => 'ttl.json',
                'description' => 'Controls cache lifetimes for API calls.',
            ],
            [
                'name' => 'thresholds.json',
                'description' => 'Configures alert thresholds and escalation rules.',
            ],
        ];

        $envPath = rtrim($configRoot, '/') . '/' . $environment;

        return array_map(function (array $file) use ($envPath) {
            $absolutePath = $envPath . '/' . $file['name'];

            return [
                'name' => $file['name'],
                'description' => $file['description'],
                'path' => $this->app->paths->toRelativePath($absolutePath),
                'exists' => file_exists($absolutePath),
            ];
        }, $files);
    }

    // ────────────────────────────────────────────────
    // Server Configuration Summarization
    // ────────────────────────────────────────────────

    /**
     * Summarize server configuration values.
     *
     * @param array<string,mixed> $servers Server configurations
     *
     * @return array<int,array<string,mixed>> Summarized server data
     */
    private function summarizeServersConfig(array $servers): array
    {
        $summary = [];

        foreach ($servers as $key => $config) {
            if (!is_array($config)) {
                continue;
            }

            $summary[] = [
                'key' => (string) $key,
                'title' => (string) ($config['title'] ?? ''),
                'url' => (string) ($config['url'] ?? ''),
                'api_url' => (string) ($config['api_url'] ?? ''),
                'api_key' => $this->maskSecret($config['api_key'] ?? ''),
                'info' => (string) ($config['info'] ?? ''),
                'colors' => [
                    'primary' => $config['primary_color'] ?? null,
                    'secondary' => $config['secondary_color'] ?? null,
                ],
                'extras' => $this->extractServerExtras($config),
            ];
        }

        usort($summary, static fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $summary;
    }

    /**
     * Extract non-standard server config options for display.
     *
     * @param array<string,mixed> $config Server configuration array
     *
     * @return array<int,array<string,string>> Extra configuration key-value pairs
     */
    private function extractServerExtras(array $config): array
    {
        $knownKeys = [
            'title',
            'url',
            'api_url',
            'api_key',
            'primary_color',
            'secondary_color',
            'info',
        ];

        $extras = [];

        foreach ($config as $key => $value) {
            if (in_array($key, $knownKeys, true) || !is_scalar($value)) {
                continue;
            }

            $extras[] = [
                'label' => (string) $key,
                'value' => (string) $value,
            ];
        }

        usort($extras, static fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $extras;
    }

    // ────────────────────────────────────────────────
    // TTL Configuration Summarization
    // ────────────────────────────────────────────────

    /**
     * Build display data for TTL configuration.
     *
     * @param array<string,float|int> $ttl TTL configuration
     *
     * @return array<int,array<string,mixed>> Formatted TTL data
     */
    private function summarizeTtlConfig(array $ttl): array
    {
        $summary = [];

        foreach ($ttl as $key => $value) {
            $seconds = (int) $value;
            $summary[] = [
                'key' => (string) $key,
                'seconds' => $seconds,
                'formatted' => $this->tools->formatDuration($seconds),
            ];
        }

        usort($summary, static fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $summary;
    }

    // ────────────────────────────────────────────────
    // Thresholds Configuration Summarization
    // ────────────────────────────────────────────────

    /**
     * Flatten thresholds configuration for display.
     *
     * @param array<string,mixed> $thresholds Thresholds configuration
     *
     * @return array<int,array<string,mixed>> Flattened threshold data
     */
    private function summarizeThresholdsConfig(array $thresholds): array
    {
        $summary = [];

        foreach ($thresholds as $section => $metrics) {
            if (!is_array($metrics)) {
                continue;
            }

            $sectionMetrics = [];

            foreach ($metrics as $metric => $levels) {
                if (is_array($levels)) {
                    $levelItems = [];
                    foreach ($levels as $label => $value) {
                        if (is_scalar($value)) {
                            $levelItems[] = [
                                'label' => (string) $label,
                                'value' => $value,
                            ];
                        }
                    }

                    $sectionMetrics[] = [
                        'metric' => (string) $metric,
                        'levels' => $levelItems,
                    ];

                    continue;
                }

                $sectionMetrics[] = [
                    'metric' => (string) $metric,
                    'levels' => [
                        [
                            'label' => 'value',
                            'value' => $levels,
                        ],
                    ],
                ];
            }

            if ($sectionMetrics !== []) {
                usort($sectionMetrics, static fn ($a, $b) => strcmp($a['metric'], $b['metric']));

                $summary[] = [
                    'section' => (string) $section,
                    'metrics' => $sectionMetrics,
                ];
            }
        }

        usort($summary, static fn ($a, $b) => strcmp($a['section'], $b['section']));

        return $summary;
    }

    // ────────────────────────────────────────────────
    // Utility Methods
    // ────────────────────────────────────────────────

    /**
     * Mask sensitive values by hiding all but the last four characters.
     *
     * @param null|string $value Sensitive value to mask
     *
     * @return string Masked value
     */
    private function maskSecret(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        $visible = substr($value, -4);

        return str_repeat('*', $length - 4) . $visible;
    }
}
