<?php

declare(strict_types=1);

namespace Mata\Services\Collect\Demo;

/**
 * Builds the demo application map for a server.
 *
 * Extracted verbatim from MockServerDataCollector::generateDemoApps(): the
 * static data now lives in {@see DemoAppCatalog} and the weighting/random
 * helpers in {@see WeightedRandom}; the assembly algorithm (weighting →
 * environment selection → status roll → prod bias → session assignment → log
 * selection → outlier injection → fatal cursor) is unchanged.
 */
final class DemoAppGenerator
{
    public function __construct(
        private readonly DemoAppCatalog $catalog,
        private readonly WeightedRandom $random,
    ) {}

    /**
     * Generate the demo application map for a server.
     *
     * @param array<string,mixed> $serverConfig server configuration (expects a `url` key)
     *
     * @return array<string,mixed>
     */
    public function generate(string $serverName, array $serverConfig): array
    {
        $appTemplates = $this->catalog->appTemplates();
        $serverPresets = $this->catalog->serverPresets();
        $presetKeyMap = $this->catalog->presetKeyMap();
        $environmentSessionProfiles = $this->catalog->environmentSessionProfiles();
        $categorySessionMultipliers = $this->catalog->categorySessionMultipliers();
        $logSizeBase = $this->catalog->logSizeBase();

        $presetKey = $presetKeyMap[$serverName] ?? 'mixed_all';

        /** @var array<string,mixed> $preset */
        $preset = $serverPresets[$presetKey];

        $appWeights = [];
        foreach ($appTemplates as $key => $template) {
            $category = $template['category'] ?? 'misc';
            $weight = $template['base_weight'] ?? 1.0;

            if (isset($preset['preferred_categories'][$category])) {
                $weight *= $preset['preferred_categories'][$category];
            }

            if (isset($preset['app_weight_overrides'][$key])) {
                $weight = max($weight, $preset['app_weight_overrides'][$key]);
            }

            $appWeights[$key] = max($weight, 0.1);
        }

        $maxPossible = min($preset['app_count_range'][1], count($appTemplates));
        $minPossible = min($preset['app_count_range'][0], $maxPossible);
        $appCount = mt_rand($minPossible, $maxPossible);
        $selectedAppKeys = $this->random->selectWeightedUnique($appWeights, $appCount);

        $apps = [];
        $unconfiguredAssigned = false;
        $errorSessionAssigned = false;
        $lastAppKey = $selectedAppKeys[count($selectedAppKeys) - 1] ?? null;

        foreach ($selectedAppKeys as $appKey) {
            $template = $appTemplates[$appKey];
            $category = $template['category'] ?? 'misc';

            $allowedEnvironments = $template['suitable_environments'] ?? array_keys($preset['environment_weights']);
            $intersected = array_intersect($allowedEnvironments, array_keys($preset['environment_weights']));

            $allowedEnvironments = array_values($intersected);
            if (empty($allowedEnvironments)) {
                $allowedEnvironments = array_keys($preset['environment_weights']);
            }

            $envWeights = array_intersect_key($preset['environment_weights'], array_flip($allowedEnvironments));

            if (empty($envWeights)) {
                $envWeights = $preset['environment_weights'];
            }

            $environment = $this->random->pickWeightedKey($envWeights);

            $fatalCount = $this->random->rollProfile($preset['status_profile']['fatal']);
            $warningCount = $this->random->rollProfile($preset['status_profile']['warnings']);

            if ($environment === 'prod' && isset($preset['prod_environment_bias'])) {
                $bias = $preset['prod_environment_bias'];
                $cleanProbability = max(0.0, min(1.0, (float) ($bias['clean_probability'] ?? 0.8)));
                if (mt_rand(1, 1000) <= (int) round($cleanProbability * 1000)) {
                    $fatalCount = 0;
                    $warningCount = 0;
                } else {
                    $maxFatal = isset($bias['max_fatal']) ? max(0, (int) $bias['max_fatal']) : null;
                    $maxWarnings = isset($bias['max_warnings']) ? max(0, (int) $bias['max_warnings']) : null;

                    if ($maxFatal !== null && $fatalCount > $maxFatal) {
                        $fatalCount = mt_rand(0, $maxFatal);
                    } elseif ($fatalCount > 0) {
                        $suppressFatal = max(0.0, min(1.0, (float) ($bias['suppress_fatal_probability'] ?? 0.55)));
                        if (mt_rand(1, 1000) <= (int) round($suppressFatal * 1000)) {
                            $fatalCount = 0;
                        }
                    }

                    if ($maxWarnings !== null && $warningCount > $maxWarnings) {
                        $warningCount = mt_rand(0, $maxWarnings);
                    } elseif ($warningCount > 0) {
                        $suppressWarnings = max(0.0, min(1.0, (float) ($bias['suppress_warning_probability'] ?? 0.45)));
                        if (mt_rand(1, 1000) <= (int) round($suppressWarnings * 1000)) {
                            $warningCount = mt_rand(0, $warningCount);
                        }
                    }
                }
            }

            $statusBaseRange = $preset['status_total_base'] ?? [150, 400];
            $statusTotal = $this->random->randomFromRange($statusBaseRange, $preset['session_multiplier'] ?? 1.0);
            $statusWeights = $preset['status_total_weight'] ?? ['fatal' => 18, 'warnings' => 5];
            $statusTotal += (int) round($fatalCount * ($statusWeights['fatal'] ?? 18));
            $statusTotal += (int) round($warningCount * ($statusWeights['warnings'] ?? 5));
            $statusTotal = max($statusTotal, $fatalCount + $warningCount);

            $sessionProfile = $environmentSessionProfiles[$environment] ?? $environmentSessionProfiles['prod'];
            $categoryMultiplier = $categorySessionMultipliers[$category] ?? 0.8;
            $sessionMultiplier = ($preset['session_multiplier'] ?? 1.0) * $categoryMultiplier;

            $sessionsHour = max(0, $this->random->randomFromRange($sessionProfile['hour'], $sessionMultiplier));
            $sessionsDay = max($sessionsHour * mt_rand(12, 24), $this->random->randomFromRange($sessionProfile['day'], $sessionMultiplier));
            $sessionsTotal = max($sessionsDay * mt_rand(6, 12), $this->random->randomFromRange($sessionProfile['total'], $sessionMultiplier));

            $session = [
                'type' => 'file',
                'status' => 'ok',
                'count_last_hour' => $sessionsHour,
                'count_last_day' => $sessionsDay,
                'count_total' => $sessionsTotal,
                'path' => '/var/lib/php/sessions',
                'size_kb' => round($sessionsTotal * 1.8, 1),
            ];

            if ($environment === 'dev' && !$unconfiguredAssigned) {
                $session = [
                    'type' => 'none',
                    'status' => 'unconfigured',
                    'count_last_hour' => null,
                    'count_last_day' => null,
                    'count_total' => null,
                    'path' => null,
                    'size_kb' => null,
                ];
                $unconfiguredAssigned = true;
            } elseif ($environment === 'prod' && mt_rand(1, 100) <= 25) {
                $session = [
                    'type' => 'redis',
                    'status' => 'ok',
                    'count_last_hour' => null,
                    'count_last_day' => null,
                    'count_total' => $sessionsTotal,
                    'path' => null,
                    'size_kb' => null,
                ];
            }

            if ($appKey === $lastAppKey && $session['status'] !== 'unconfigured') {
                $session = [
                    'type' => 'memcached',
                    'status' => 'unsupported',
                    'count_last_hour' => null,
                    'count_last_day' => null,
                    'count_total' => null,
                    'path' => null,
                    'size_kb' => null,
                    'error' => 'session handler memcached is not supported',
                ];
                $errorSessionAssigned = true;
            }

            $logCandidates = $template['log_templates'] ?? ['logs/error.log', 'logs/debug.log', 'logs/app.log'];
            $maxLogs = min(count($logCandidates), $preset['log_files']['max'] ?? count($logCandidates));
            $minLogs = max(1, min($preset['log_files']['min'] ?? 1, $maxLogs));
            $logCount = mt_rand($minLogs, $maxLogs);
            $logSelection = $logCandidates;
            shuffle($logSelection);
            $logSelection = array_slice($logSelection, 0, $logCount);

            $logSizeMultiplier = $preset['log_size_multiplier'] ?? 1.0;
            $logBaseRange = $logSizeBase[$environment] ?? [100, 8000];
            $logs = [];
            foreach ($logSelection as $logRelativePath) {
                $rangeMin = (int) max(50, round($logBaseRange[0] * $logSizeMultiplier));
                $rangeMax = (int) max($rangeMin + 10, round($logBaseRange[1] * $logSizeMultiplier));
                $size = mt_rand($rangeMin, $rangeMax);
                $logs[$template['path'] . '/' . ltrim($logRelativePath, '/')] = (string) $size;
            }

            $apps[$appKey] = [
                'title' => $template['title'],
                'description' => $template['description'] ?? 'Application monitored by Mata.',
                'path' => $template['path'],
                'url' => rtrim($serverConfig['url'], '/') . '/' . $template['path'],
                'status_url' => rtrim($serverConfig['url'], '/') . '/' . $template['path'] . '/status.php',
                'env' => $environment,
                'composer' => true,
                'status' => [
                    'fatal' => $fatalCount,
                    'warnings' => $warningCount,
                    'total' => $statusTotal,
                ],
                'session' => $session,
                'logs' => $logs,
            ];
        }

        if (!$errorSessionAssigned) {
            foreach (array_reverse(array_keys($apps)) as $appKey) {
                if (($apps[$appKey]['session']['status'] ?? null) === 'unconfigured') {
                    continue;
                }

                $apps[$appKey]['session'] = [
                    'type' => 'memcached',
                    'status' => 'unsupported',
                    'count_last_hour' => null,
                    'count_last_day' => null,
                    'count_total' => null,
                    'path' => null,
                    'size_kb' => null,
                    'error' => 'session handler memcached is not supported',
                ];
                $errorSessionAssigned = true;

                break;
            }
        }

        $appKeys = array_keys($apps);
        $totalApps = count($appKeys);
        if ($totalApps > 0) {
            $outlierTargets = [];
            $groupSize = 6;

            for ($offset = 0; $offset < $totalApps; $offset += $groupSize) {
                $chunk = array_slice($appKeys, $offset, $groupSize);
                if (empty($chunk)) {
                    continue;
                }

                $chunkCount = count($chunk);
                $outlierCount = min($chunkCount, mt_rand(1, min(2, $chunkCount)));
                $chunkShuffled = $chunk;
                shuffle($chunkShuffled);
                $outlierTargets = array_merge($outlierTargets, array_slice($chunkShuffled, 0, $outlierCount));
            }

            $outlierProfile = $preset['outlier_profile'] ?? [];
            $fatalRange = $outlierProfile['fatal'] ?? [25, 60];
            $warningRange = $outlierProfile['warnings'] ?? [70, 200];
            $distribution = $outlierProfile['distribution'] ?? ['warnings' => 0.5, 'fatal' => 0.3, 'both' => 0.2];

            foreach (array_unique($outlierTargets) as $appKey) {
                if (!isset($apps[$appKey])) {
                    continue;
                }

                $outlierType = $this->random->pickWeightedKey([
                    'fatal' => $distribution['fatal'] ?? 0.3,
                    'warnings' => $distribution['warnings'] ?? 0.5,
                    'both' => $distribution['both'] ?? 0.2,
                ]);

                if ($outlierType === 'fatal' || $outlierType === 'both') {
                    $apps[$appKey]['status']['fatal'] = max(
                        $apps[$appKey]['status']['fatal'],
                        $this->random->randomFromRange($fatalRange, 1.0)
                    );
                }

                if ($outlierType === 'warnings' || $outlierType === 'both') {
                    $apps[$appKey]['status']['warnings'] = max(
                        $apps[$appKey]['status']['warnings'],
                        $this->random->randomFromRange($warningRange, 1.0)
                    );
                }

                $statusWeights = $preset['status_total_weight'] ?? ['fatal' => 18, 'warnings' => 5];
                $apps[$appKey]['status']['total'] = max(
                    $apps[$appKey]['status']['total'],
                    (int) round($apps[$appKey]['status']['fatal'] * ($statusWeights['fatal'] ?? 18)
                        + $apps[$appKey]['status']['warnings'] * ($statusWeights['warnings'] ?? 5))
                );

            }
        }

        foreach ($apps as $appKey => &$app) {
            $app['status']['total'] = max(
                $app['status']['total'],
                $app['status']['fatal'] + $app['status']['warnings']
            );
            $app['status']['fatal_cursor'] = $app['status']['fatal'] === 0
                ? null
                : 'v1:' . hash('sha256', $appKey . ':' . $app['status']['fatal']);
        }
        unset($app);

        return $this->applyMonitoringSettings($apps);
    }

    /**
     * Add disabled demo states and omit their unavailable payload fields.
     *
     * @param array<string,mixed> $apps
     *
     * @return array<string,mixed>
     */
    private function applyMonitoringSettings(array $apps): array
    {
        $appKeys = array_keys($apps);
        if ($appKeys === []) {
            return $apps;
        }

        /** @var array<string,array<string,false>> $disabledByApp */
        $disabledByApp = [];
        foreach (['loganalysis', 'logsize', 'sessions', 'reachability'] as $index => $setting) {
            $appKey = $appKeys[$index % count($appKeys)];
            $disabledByApp[$appKey][$setting] = false;
        }

        foreach ($disabledByApp as $appKey => $disabled) {
            if (!is_array($apps[$appKey])) {
                continue;
            }

            $app = $apps[$appKey];
            $app['monitoring'] = $disabled;
            if (isset($disabled['loganalysis'])) {
                unset($app['status']);
            }
            if (isset($disabled['logsize'])) {
                unset($app['logs']);
            }
            if (isset($disabled['sessions'])) {
                unset($app['session']);
            }

            $apps[$appKey] = $app;
        }

        return $apps;
    }
}
