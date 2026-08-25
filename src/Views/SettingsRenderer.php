<?php

declare(strict_types=1);

namespace Mata\Views;

use DirectoryIterator;
use Mata\Config\Dto\MailConfig;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\IntervalsConfig;
use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\Section\ThresholdsConfig;
use Mata\Config\Monitoring\Section\TtlConfig;
use Mata\Utils\UsersFilePathResolver;
use UnexpectedValueException;

/**
 * Settings renderer for configuration overview.
 *
 * Handles rendering of application settings and configuration data:
 * - Configuration file metadata and existence checks
 * - Server configuration summaries with masked secrets
 * - TTL (cache timeout) configuration display
 * - Threshold configuration for monitoring alarms
 * - Cooldown intervals, probe behaviour, and alarm rate limits
 * - Mail transport summary with credentials hidden
 */
class SettingsRenderer extends BaseRenderer
{
    /**
     * Render settings container with configuration overview.
     *
     * @param string                     $appEnvironment   Application environment
     * @param ServersConfig              $serversConfig    Servers configuration
     * @param TtlConfig                  $ttlConfig        Cache TTLs in seconds
     * @param ThresholdsConfig           $thresholdsConfig Thresholds configuration
     * @param IntervalsConfig            $intervalsConfig  Event and alarm cooldown intervals
     * @param ProbesConfig               $probesConfig     Probe timeout and retry configuration
     * @param null|AlarmRateLimitsConfig $alarmRateLimits  Alarm delivery rate limits
     * @param null|MailConfig            $mailConfig       Mail transport and recipient configuration
     *
     * @return string Rendered HTML
     */
    public function renderContainer(
        string $appEnvironment,
        ServersConfig $serversConfig,
        TtlConfig $ttlConfig,
        ThresholdsConfig $thresholdsConfig,
        IntervalsConfig $intervalsConfig,
        ProbesConfig $probesConfig,
        ?AlarmRateLimitsConfig $alarmRateLimits = null,
        ?MailConfig $mailConfig = null
    ): string {
        $configRoot = $this->app->paths->appPath . '/config';
        $usersFilePath = UsersFilePathResolver::resolve($this->app->paths->appPath);

        return $this->twig->render('partials/containers/settings-container.html.twig', [
            'settings' => [
                'environment' => $appEnvironment,
                'app_environment' => $appEnvironment,
                'config_root' => $this->app->paths->toRelativePath($configRoot),
                'files' => $this->buildConfigFilesMetadata($configRoot, $usersFilePath),
                'servers' => $this->summarizeServersConfig($serversConfig->values),
                'ttl' => $this->summarizeTtlConfig($ttlConfig),
                'thresholds' => $this->summarizeThresholdsConfig($thresholdsConfig->toArray()),
                'intervals' => $this->summarizeIntervalsConfig($intervalsConfig->toArray()),
                'probes' => $this->summarizeProbesConfig($probesConfig->toArray()),
                'limits' => $this->summarizeAlarmLimits($alarmRateLimits),
                'mail' => $this->summarizeMailConfig($mailConfig),
            ],
        ]);
    }

    // ────────────────────────────────────────────────
    // Configuration File Metadata
    // ────────────────────────────────────────────────

    /**
     * Build metadata about every dashboard configuration file.
     *
     * Expected files are shown even when missing. Additional JSON and ENV files
     * in the configuration root are included automatically.
     *
     * @param string $configRoot    Configuration root directory path
     * @param string $usersFilePath Resolved authentication file path
     *
     * @return array<int,array<string,mixed>> Configuration files metadata
     */
    private function buildConfigFilesMetadata(string $configRoot, string $usersFilePath): array
    {
        $configRoot = rtrim($configRoot, '/');
        $files = [
            ['name' => 'config.env', 'description' => 'Application, logging, and runtime settings.', 'path' => $configRoot . '/config.env'],
            ['name' => 'db.config.env', 'description' => 'Database connection and TLS settings.', 'path' => $configRoot . '/db.config.env'],
            ['name' => 'mail.config.env', 'description' => 'Email and SMTP settings for notifications.', 'path' => $configRoot . '/mail.config.env'],
            ['name' => 'limits.json', 'description' => 'Alarm delivery rate limits by channel and server.', 'path' => $configRoot . '/limits.json'],
            ['name' => 'servers.json', 'description' => 'Defines monitored servers and their API credentials.', 'path' => $configRoot . '/servers.json'],
            ['name' => 'ttl.json', 'description' => 'Controls cache lifetimes for API calls.', 'path' => $configRoot . '/ttl.json'],
            ['name' => 'thresholds.json', 'description' => 'Configures alarm thresholds and escalation rules.', 'path' => $configRoot . '/thresholds.json'],
            ['name' => 'notification_rules.json', 'description' => 'Controls notification enablement and email routing.', 'path' => $configRoot . '/notification_rules.json'],
            ['name' => 'data_retention.json', 'description' => 'Controls metric, event, and alarm retention.', 'path' => $configRoot . '/data_retention.json'],
            ['name' => 'intervals.json', 'description' => 'Controls event and alarm cooldown intervals.', 'path' => $configRoot . '/intervals.json'],
            ['name' => 'probes.json', 'description' => 'Controls probe timeout, retry, and backoff behaviour.', 'path' => $configRoot . '/probes.json'],
            ['name' => 'menu.json', 'description' => 'Overrides top-menu item visibility.', 'path' => $configRoot . '/menu.json'],
            ['name' => 'users.json', 'description' => 'Stores dashboard user accounts and authentication data.', 'path' => $usersFilePath],
        ];

        $knownPaths = array_column($files, 'path');
        $additional = [];
        if (is_dir($configRoot) && is_readable($configRoot)) {
            try {
                foreach (new DirectoryIterator($configRoot) as $file) {
                    if ($file->getFilename() === 'users.json'
                        || !$file->isFile()
                        || !in_array($file->getExtension(), ['env', 'json'], true)) {
                        continue;
                    }

                    $path = $file->getPathname();
                    if (in_array($path, $knownPaths, true)) {
                        continue;
                    }

                    $additional[] = [
                        'name' => $file->getFilename(),
                        'description' => 'Additional dashboard configuration file.',
                        'path' => $path,
                    ];
                    $knownPaths[] = $path;
                }
            } catch (UnexpectedValueException) {
                // Expected file metadata remains available when the directory cannot be listed.
            }
        }

        // Directory iteration order is filesystem-dependent, so sort for a stable listing.
        usort($additional, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $files = array_merge($files, $additional);

        return array_map(function (array $file) {
            return [
                'name' => $file['name'],
                'description' => $file['description'],
                'path' => $this->displayConfigPath($file['path']),
                'exists' => is_file($file['path']),
            ];
        }, $files);
    }

    private function displayConfigPath(string $path): string
    {
        $appPath = rtrim($this->app->paths->appPath, '/');

        return str_starts_with($path, $appPath . '/')
            ? $this->app->paths->toRelativePath($path)
            : $path;
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
     * @return array<int,array<string,mixed>> Formatted TTL data
     */
    private function summarizeTtlConfig(TtlConfig $ttl): array
    {
        $summary = [];

        foreach ($ttl->values as $key => $seconds) {
            $summary[] = [
                'key' => $key,
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
    // Intervals Configuration Summarization
    // ────────────────────────────────────────────────

    /**
     * Flatten cooldown intervals for display.
     *
     * Values are minutes; a zero means the cooldown is disabled.
     *
     * @param array<string,mixed> $intervals Intervals configuration
     *
     * @return array<int,array<string,mixed>> Flattened interval data
     */
    private function summarizeIntervalsConfig(array $intervals): array
    {
        $summary = [];

        foreach ($intervals as $section => $groups) {
            if (!is_array($groups)) {
                continue;
            }

            $sectionGroups = [];

            foreach ($groups as $group => $entries) {
                $groupEntries = [];

                foreach (is_array($entries) ? $entries : ['value' => $entries] as $key => $value) {
                    if (!is_numeric($value)) {
                        continue;
                    }

                    $minutes = (int) $value;
                    $groupEntries[] = [
                        'key' => (string) $key,
                        'minutes' => $minutes,
                        'formatted' => $minutes === 0 ? 'no cooldown' : $this->tools->formatDuration($minutes * 60),
                        'is_default' => $key === '_default',
                    ];
                }

                if ($groupEntries !== []) {
                    usort($groupEntries, static fn ($a, $b) => strcmp($a['key'], $b['key']));

                    $sectionGroups[] = [
                        'group' => (string) $group,
                        'entries' => $groupEntries,
                    ];
                }
            }

            if ($sectionGroups !== []) {
                usort($sectionGroups, static fn ($a, $b) => strcmp($a['group'], $b['group']));

                $summary[] = [
                    'section' => (string) $section,
                    'groups' => $sectionGroups,
                ];
            }
        }

        usort($summary, static fn ($a, $b) => strcmp($a['section'], $b['section']));

        return $summary;
    }

    // ────────────────────────────────────────────────
    // Probes Configuration Summarization
    // ────────────────────────────────────────────────

    /**
     * Build display data for probe timeout and retry configuration.
     *
     * @param array<string,mixed> $probes Probes configuration
     *
     * @return array<int,array<string,mixed>> Formatted probe data
     */
    private function summarizeProbesConfig(array $probes): array
    {
        $summary = [];

        foreach ($probes as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $summary[] = [
                'key' => (string) $key,
                'value' => $value,
                'formatted' => $this->formatProbeValue((string) $key, $value),
            ];
        }

        usort($summary, static fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $summary;
    }

    /**
     * Render a probe value with the unit implied by its key suffix.
     *
     * @param string                $key   Configuration key
     * @param bool|float|int|string $value Raw configuration value
     *
     * @return string Human readable value
     */
    private function formatProbeValue(string $key, bool|float|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'enabled' : 'disabled';
        }

        if (!is_numeric($value)) {
            return (string) $value;
        }

        if (str_ends_with($key, '_ms')) {
            return ((int) $value) . ' ms';
        }

        if (str_ends_with($key, '_seconds')) {
            return $this->tools->formatDuration((int) $value);
        }

        return (string) $value;
    }

    // ────────────────────────────────────────────────
    // Alarm Limits Summarization
    // ────────────────────────────────────────────────

    /**
     * Build display data for alarm delivery rate limits.
     *
     * @param null|AlarmRateLimitsConfig $limits Alarm limits configuration
     *
     * @return array<string,mixed> Formatted limits data
     */
    private function summarizeAlarmLimits(?AlarmRateLimitsConfig $limits): array
    {
        if ($limits === null) {
            return ['default' => null, 'channels' => [], 'overrides' => []];
        }

        $channels = [];
        foreach ($limits->getChannelLimits() as $channel => $limit) {
            $channels[] = ['channel' => (string) $channel, 'limits' => $limit->toArray()];
        }
        usort($channels, static fn ($a, $b) => strcmp($a['channel'], $b['channel']));

        $overrides = [];
        foreach ($limits->getServerOverrides() as $server => $channelLimits) {
            $serverChannels = [];
            foreach ($channelLimits as $channel => $limit) {
                $serverChannels[] = ['channel' => (string) $channel, 'limits' => $limit->toArray()];
            }
            usort($serverChannels, static fn ($a, $b) => strcmp($a['channel'], $b['channel']));

            $overrides[] = ['server' => (string) $server, 'channels' => $serverChannels];
        }
        usort($overrides, static fn ($a, $b) => strcmp($a['server'], $b['server']));

        return [
            'default' => $limits->getGlobalDefault()->toArray(),
            'channels' => $channels,
            'overrides' => $overrides,
        ];
    }

    // ────────────────────────────────────────────────
    // Mail Configuration Summarization
    // ────────────────────────────────────────────────

    /**
     * Build display data for mail transport and recipient configuration.
     *
     * The transport password is never exposed, and a credential carried in the
     * DSN user component (API-key style DSNs) is masked as well.
     *
     * @param null|MailConfig $mail Mail configuration
     *
     * @return array<string,mixed> Formatted mail data
     */
    private function summarizeMailConfig(?MailConfig $mail): array
    {
        if ($mail === null) {
            return ['configured' => false];
        }

        return [
            'configured' => true,
            'transport' => $this->summarizeMailerDsn($mail->dsn),
            'from_email' => (string) $mail->fromEmail,
            'from_name' => (string) $mail->fromName,
            'charset' => $mail->charset,
            'dashboard_url' => (string) $mail->dashboardUrl,
            'recipients' => [
                ['label' => 'Alarm', 'addresses' => $mail->alarmRecipients],
                ['label' => 'Test', 'addresses' => $mail->testRecipients],
                ['label' => 'Dev', 'addresses' => $mail->devRecipients],
            ],
        ];
    }

    /**
     * Split a mailer DSN into displayable parts, hiding the credentials.
     *
     * @param null|string $dsn Symfony mailer DSN
     *
     * @return array<string,mixed> Transport parts
     */
    private function summarizeMailerDsn(?string $dsn): array
    {
        $dsn = (string) $dsn;
        if ($dsn === '') {
            return ['configured' => false];
        }

        $parts = parse_url($dsn);
        if ($parts === false) {
            return ['configured' => true, 'scheme' => '', 'host' => '', 'port' => null, 'username' => '', 'has_password' => false];
        }

        $user = isset($parts['user']) ? urldecode((string) $parts['user']) : '';
        $hasPassword = isset($parts['pass']) && (string) $parts['pass'] !== '';

        return [
            'configured' => true,
            'scheme' => (string) ($parts['scheme'] ?? ''),
            'host' => (string) ($parts['host'] ?? ''),
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            // Without a password component the user field is itself the secret (e.g. sendgrid+api://KEY@default).
            'username' => $hasPassword ? $user : $this->maskSecret($user),
            'has_password' => $hasPassword,
        ];
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
