<?php

declare(strict_types=1);

namespace Mata\Services\Collect\Demo;

/**
 * Builds the demo application map for a server.
 *
 * Extracted verbatim from MockServerDataCollector::generateDemoApps(): the
 * static data now lives in {@see DemoAppCatalog} and the weighting/random
 * helpers in {@see WeightedRandom}. Apps are mostly healthy; one app on the
 * {@see DemoAppCatalog::incident()} server gets fatal errors, and one app on the
 * {@see DemoAppCatalog::unprobedAppServer()} server is not probed for reachability.
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

        $warningProfile = $this->catalog->warningProfile();
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

            $fatalCount = 0;
            $warningCount = $this->random->rollProfile($warningProfile);

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

            if ($environment === 'prod' && mt_rand(1, 100) <= 25) {
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
            if (isset($template['framework'])) {
                $apps[$appKey]['framework'] = $template['framework'];
            }
        }

        $apps = $this->injectIncident($serverName, $apps, $preset);
        $apps = $this->injectUnprobedApp($serverName, $apps);

        foreach ($apps as $appKey => &$app) {
            $status = is_array($app['status'] ?? null) ? $app['status'] : [];
            $fatal = is_int($status['fatal'] ?? null) ? $status['fatal'] : 0;
            $warnings = is_int($status['warnings'] ?? null) ? $status['warnings'] : 0;
            $status['fatal'] = $fatal;
            $status['warnings'] = $warnings;
            $status['total'] = max(
                is_int($status['total'] ?? null) ? $status['total'] : 0,
                $fatal + $warnings
            );
            $status['fatal_cursor'] = $fatal === 0
                ? null
                : 'v1:' . hash('sha256', $appKey . ':' . $fatal);
            $app['status'] = $status;
        }
        unset($app);

        return $apps;
    }

    /**
     * Give one app on the incident server fatal errors.
     *
     * @param array<string,mixed> $apps
     * @param array<string,mixed> $preset
     *
     * @return array<string,mixed>
     */
    private function injectIncident(string $serverName, array $apps, array $preset): array
    {
        $incident = $this->catalog->incident();
        if ($serverName !== $incident['server'] || $apps === []) {
            return $apps;
        }

        $appKey = array_rand($apps);
        $status = $apps[$appKey]['status'];
        $status['fatal'] = $this->random->randomFromRange($incident['fatal']);
        $statusWeights = $preset['status_total_weight'] ?? ['fatal' => 18, 'warnings' => 5];
        $status['total'] = max(
            $status['total'],
            (int) round($status['fatal'] * ($statusWeights['fatal'] ?? 18) + $status['warnings'] * ($statusWeights['warnings'] ?? 5))
        );
        $apps[$appKey]['status'] = $status;

        return $apps;
    }

    /**
     * Turn off reachability monitoring for one app on the unprobed-app server.
     *
     * Picks the first app instead of a random one so the seeded sequence stays unchanged.
     *
     * @param array<string,mixed> $apps
     *
     * @return array<string,mixed>
     */
    private function injectUnprobedApp(string $serverName, array $apps): array
    {
        $appKey = array_key_first($apps);
        if ($serverName !== $this->catalog->unprobedAppServer() || $appKey === null) {
            return $apps;
        }

        $apps[$appKey]['monitoring'] = ['reachability' => false];

        return $apps;
    }
}
