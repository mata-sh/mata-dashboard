<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use FilesystemIterator;
use Mata\Config\ConfigSource;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\EnvConfigException;
use Mata\Config\EnvConfigLoader;
use Mata\Config\Monitoring\Loader\AbstractJsonConfigLoader;
use Mata\Config\Monitoring\Loader\AdvisoriesConfigLoader;
use Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader;
use Mata\Config\Monitoring\Loader\ChannelsConfigLoader;
use Mata\Config\Monitoring\Loader\ChartsConfigLoader;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadProblem;
use Mata\Config\Monitoring\Loader\IntervalsConfigLoader;
use Mata\Config\Monitoring\Loader\MenuConfigLoader;
use Mata\Config\Monitoring\Loader\MonitoringConfigLoadException;
use Mata\Config\Monitoring\Loader\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\Loader\ProbesConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\ThresholdsConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Services\Status\StatusLevel;
use Psr\Log\LogLevel;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Validates an instance config directory with the same loaders the web app and cron use, so
 * a file passes here exactly when the app would accept it. Problems the app would only log
 * become a report instead; files the app would replace with defaults wholesale are errors.
 */
final class InstanceConfigValidator
{
    /**
     * Reported types that are errors although the file loads: every invalid servers entry drops
     * that server (or its key). A file the app would ignore is rejected, which is always an error.
     */
    private const ERROR_TYPES = ['servers_config_invalid_entry'];

    /** Files a JSON loader reads next to its own; validated, and selectable as $onlyFile, together with it. */
    private const COMPANION_FILES = [ServersConfigLoader::SERVERS_FILE => [ServersConfigLoader::SECRETS_FILE]];

    /** Local Rego policies, loaded by OPA next to the shipped policy/ directory. */
    public const POLICY_DIR = 'policy';

    /**
     * @param ?string $onlyFile validate only the files loaded together with this one: the three env
     *                          files, or servers.json with servers.secrets.json
     */
    public function validate(string $configPath, ?string $onlyFile = null): ConfigValidationReport
    {
        $configPath = rtrim($configPath, '/');
        $jsonChecks = $this->jsonChecks();
        $selected = static fn (array $files): bool => $onlyFile === null || in_array($onlyFile, $files, true);

        $files = [];
        foreach ([EnvConfigLoader::INSTANCE_FILES, ...array_column($jsonChecks, 'files')] as $checkFiles) {
            if (!$selected($checkFiles)) {
                continue;
            }
            foreach ($checkFiles as $file) {
                $files[$file] = is_file($configPath . '/' . $file);
            }
        }

        // Env files always load: the JSON loaders read their variables (channels.json *_env keys).
        $loader = new EnvConfigLoader();
        [$envProblems, $config] = $this->validateEnv($configPath, $loader);
        
        if (!$selected(EnvConfigLoader::INSTANCE_FILES)) {
            $envProblems = [];
        }

        // policy/ has no $onlyFile entry, so only a full run checks it.
        $policyProblems = [];
        if ($onlyFile === null && $config !== null) {
            $policyProblems = $this->validatePolicies($configPath, $config, $loader);
        }

        $jsonProblems = [];
        foreach ($jsonChecks as $check) {
            if ($selected($check['files'])) {
                $jsonProblems = [...$jsonProblems, ...$this->validateJson($configPath, $check['files'], $check['loader'])];
            }
        }

        $problems = [...$envProblems, ...$policyProblems, ...$jsonProblems];

        return new ConfigValidationReport($configPath, $files, $problems);
    }

    /** @return list<string> every file name `validate()` accepts as $onlyFile */
    public function knownFiles(): array
    {
        return [...EnvConfigLoader::INSTANCE_FILES, ...array_merge(...array_column($this->jsonChecks(), 'files'))];
    }

    /** @return array{list<ConfigProblem>,?ApplicationConfig} */
    private function validateEnv(string $configPath, EnvConfigLoader $loader): array
    {
        $problems = [];
        foreach (EnvConfigLoader::INSTANCE_FILES as $file) {
            if (!is_file($configPath . '/' . $file)) {
                $problems[] = new ConfigProblem(
                    level: StatusLevel::Error,
                    type: 'config.file.missing',
                    message: "{$file} is missing; copy it from config.example/{$file}.example",
                    sources: [ConfigSource::file($file)],
                );
            }
        }

        try {
            $config = $loader->load(EnvConfigLoader::INSTANCE_FILES, $configPath);
            $result = $loader->validateConfig($config);
        } catch (EnvConfigException $exception) {
            $problems[] = new ConfigProblem(
                level: StatusLevel::Error,
                type: 'config.env.invalid',
                message: $exception->getMessage(),
                sources: [$exception->source],
            );

            return [$problems, null];
        }

        $levelsAndEntries = [
            [StatusLevel::Error, $result['critical_errors']],
            [StatusLevel::Warning, $result['warnings']],
        ];
        foreach ($levelsAndEntries as [$level, $entries]) {

            foreach ($entries as $entry) {
                $problems[] = new ConfigProblem(
                    level: $level,
                    type: $entry['type'],
                    message: $entry['message'],
                    sources: array_map($loader->sourceOf(...), $entry['variables']),
                );
            }
        }

        return [$problems, $config];
    }

    /**
     * OPA merges these files with the shipped policy/ into one namespace: a local file that
     * declares a shipped package (a copied alarms.rego) stops OPA from loading any policy.
     *
     * @return list<ConfigProblem>
     */
    private function validatePolicies(string $configPath, ApplicationConfig $config, EnvConfigLoader $loader): array
    {
        $problems = [];
        $hasDecisionPolicy = false;
        foreach ($this->policyFiles($configPath) as $file) {
            $package = preg_match('/^\s*package\s+(\S+)/m', (string) file_get_contents($configPath . '/' . $file), $match) === 1 ? $match[1] : '';
            if ($package !== 'local' && !str_starts_with($package, 'local.')) {
                $problems[] = new ConfigProblem(
                    level: StatusLevel::Error,
                    type: 'config.policy.package.invalid',
                    message: "{$file} declares package " . ($package === '' ? '(none)' : $package) . '; local policies need a package under local, such as local.alarms, or OPA can fail to load next to the shipped policies',
                    sources: [ConfigSource::file($file)],
                );
            }
            $hasDecisionPolicy = $hasDecisionPolicy || !str_ends_with($file, '_test.rego');
        }

        $decisionProblem = $this->decisionPathProblem($config, $hasDecisionPolicy, $loader);
        if ($decisionProblem !== null) {
            $problems[] = $decisionProblem;
        }

        return $problems;
    }

    /** Warns when local policies and the configured alarm decision disagree. */
    private function decisionPathProblem(ApplicationConfig $config, bool $hasDecisionPolicy, EnvConfigLoader $loader): ?ConfigProblem
    {
        $driver = $config->alarmDecision->driver;
        $decisionPath = $config->alarmDecision->opa->decisionPath;
        $queriesLocal = str_starts_with($decisionPath, '/v1/data/local/');
        $dir = self::POLICY_DIR . '/';

        if ($driver !== 'opa') {
            if (!$hasDecisionPolicy) {
                return null;
            }

            return new ConfigProblem(
                level: StatusLevel::Warning,
                type: 'config.policy.unused',
                message: "{$dir} has local policies, but ALARM_DECISION_DRIVER={$driver} ignores them",
                sources: [$loader->sourceOf('ALARM_DECISION_DRIVER')],
            );
        }
        if ($hasDecisionPolicy && !$queriesLocal) {
            return new ConfigProblem(
                level: StatusLevel::Warning,
                type: 'config.policy.unused',
                message: "{$dir} has local policies, but OPA_ALARM_DECISION_PATH={$decisionPath} does not query them; set it to the local decision, such as /v1/data/local/alarms/decision",
                sources: [$loader->sourceOf('OPA_ALARM_DECISION_PATH')],
            );
        }
        if (!$hasDecisionPolicy && $queriesLocal) {
            return new ConfigProblem(
                level: StatusLevel::Warning,
                type: 'config.policy.missing',
                message: "OPA_ALARM_DECISION_PATH={$decisionPath} queries a local policy, but {$dir} has none; every alarm evaluation would fail",
                sources: [$loader->sourceOf('OPA_ALARM_DECISION_PATH')],
            );
        }

        return null;
    }

    /** @return list<string> .rego files below POLICY_DIR, relative to the config path */
    private function policyFiles(string $configPath): array
    {
        $dir = $configPath . '/' . self::POLICY_DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'rego') {
                $files[] = self::POLICY_DIR . substr($entry->getPathname(), strlen($dir));
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<array{files:list<string>,loader:AbstractJsonConfigLoader<covariant object>}> */
    private function jsonChecks(): array
    {
        $checks = [];
        foreach ($this->jsonLoaders() as $file => $loader) {
            $checks[] = ['files' => [$file, ...self::COMPANION_FILES[$file] ?? []], 'loader' => $loader];
        }

        return $checks;
    }

    /**
     * Loaders run without fallback, so a file the app would replace with defaults throws instead
     * of passing quietly.
     *
     * @return array<string, AbstractJsonConfigLoader<covariant object>> keyed by the file each loader reads
     */
    private function jsonLoaders(): array
    {
        return [
            ServersConfigLoader::SERVERS_FILE => new ServersConfigLoader(fallbackOnFailure: false),
            TtlConfigLoader::TTL_FILE => new TtlConfigLoader(fallbackOnFailure: false),
            DataRetentionConfigLoader::DATA_RETENTION_FILE => new DataRetentionConfigLoader(fallbackOnFailure: false),
            IntervalsConfigLoader::INTERVALS_FILE => new IntervalsConfigLoader(fallbackOnFailure: false),
            ProbesConfigLoader::PROBES_FILE => new ProbesConfigLoader(fallbackOnFailure: false),
            NotificationRulesConfigLoader::NOTIFICATION_RULES_FILE => new NotificationRulesConfigLoader(fallbackOnFailure: false),
            ThresholdsConfigLoader::THRESHOLDS_FILE => new ThresholdsConfigLoader(fallbackOnFailure: false),
            MenuConfigLoader::MENU_FILE => new MenuConfigLoader(MenuBuilder::MENU_KEYS, fallbackOnFailure: false),
            ChartsConfigLoader::CHARTS_FILE => new ChartsConfigLoader(new MetricCatalog(), new MetricQueryPolicy(), fallbackOnFailure: false),
            ChannelsConfigLoader::CHANNELS_FILE => new ChannelsConfigLoader(fallbackOnFailure: false),
            AlarmRateLimitsConfigLoader::LIMITS_FILE => new AlarmRateLimitsConfigLoader(fallbackOnFailure: false),
            AdvisoriesConfigLoader::ADVISORIES_FILE => new AdvisoriesConfigLoader(fallbackOnFailure: false),
        ];
    }

    /**
     * @param list<string>                               $files  the file the loader reads first, then any companions
     * @param AbstractJsonConfigLoader<covariant object> $loader
     *
     * @return list<ConfigProblem>
     */
    private function validateJson(string $configPath, array $files, AbstractJsonConfigLoader $loader): array
    {
        try {
            $loadProblems = $loader->load($configPath)->problems;
            $rejected = false;
        } catch (MonitoringConfigLoadException $exception) {
            // Guard only: loaders report the rejection before throwing, so the list is never empty.
            $loadProblems = $exception->problems ?: [new ConfigLoadProblem(
                'config_file_rejected',
                "{$files[0]} was rejected; the app would use defaults",
                LogLevel::ERROR,
                sources: [ConfigSource::file($files[0])],
            )];
            $rejected = true;
        }

        $problems = [];
        foreach ($loadProblems as $loadProblem) {
            $problems[] = new ConfigProblem(
                level: $this->levelFor($loadProblem, $rejected),
                type: $loadProblem->type,
                message: $loadProblem->message,
                sources: $loadProblem->sources,
            );
        }

        return $problems;
    }

    /**
     * Rejected configs are errors. Invalid server entries are promoted except the empty-server message;
     * remaining problems follow their log level: ERROR/CRITICAL are errors, all others warnings.
     *
     * @return StatusLevel::Error|StatusLevel::Warning
     */
    private function levelFor(ConfigLoadProblem $loadProblem, bool $rejected): StatusLevel
    {
        $isErrorType = in_array($loadProblem->type, self::ERROR_TYPES, true);
        $isEmptyServerMessage = $loadProblem->message === ServersConfig::EMPTY_ERROR;

        if ($rejected || ($isErrorType && !$isEmptyServerMessage)) {

            return StatusLevel::Error;
        }

        return in_array($loadProblem->level, [LogLevel::CRITICAL, LogLevel::ERROR], true) ? StatusLevel::Error : StatusLevel::Warning;
    }
}
