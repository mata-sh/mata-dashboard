<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use Closure;
use Mata\Config\ConfigSource;
use Mata\Config\EnvConfigException;
use Mata\Config\EnvConfigLoader;
use Mata\Config\Monitoring\Loader\AdvisoriesConfigLoader;
use Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader;
use Mata\Config\Monitoring\Loader\ChannelsConfigLoader;
use Mata\Config\Monitoring\Loader\ChartsConfigLoader;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadProblem;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadResult;
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

    /**
     * @param ?string $onlyFile validate only the files loaded together with this one: the three env
     *                          files, or servers.json with servers.secrets.json
     */
    public function validate(string $configPath, ?string $onlyFile = null): ConfigValidationReport
    {
        $configPath = rtrim($configPath, '/');
        $jsonChecks = $this->jsonChecks();

        $files = [];
        foreach ([EnvConfigLoader::INSTANCE_FILES, ...array_column($jsonChecks, 'files')] as $checkFiles) {
            if ($onlyFile !== null && !in_array($onlyFile, $checkFiles, true)) {
                continue;
            }
            foreach ($checkFiles as $file) {
                $files[$file] = is_file($configPath . '/' . $file);
            }
        }

        // Env files always load: the JSON loaders read their variables (channels.json *_env keys).
        $envProblems = $this->validateEnv($configPath);
        $problems = $onlyFile === null || in_array($onlyFile, EnvConfigLoader::INSTANCE_FILES, true) ? $envProblems : [];
        foreach ($jsonChecks as $check) {
            if ($onlyFile === null || in_array($onlyFile, $check['files'], true)) {
                $problems = [...$problems, ...$this->validateJson($configPath, $check['files'], $check['load'])];
            }
        }

        return new ConfigValidationReport($configPath, $files, $problems);
    }

    /** @return list<string> every file name `validate()` accepts as $onlyFile */
    public function knownFiles(): array
    {
        return [...EnvConfigLoader::INSTANCE_FILES, ...array_merge(...array_column($this->jsonChecks(), 'files'))];
    }

    /** @return list<ConfigProblem> */
    private function validateEnv(string $configPath): array
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

        $loader = new EnvConfigLoader();

        try {
            $result = $loader->validateConfig($loader->load(EnvConfigLoader::INSTANCE_FILES, $configPath));
        } catch (EnvConfigException $exception) {
            $problems[] = new ConfigProblem(
                level: StatusLevel::Error,
                type: 'config.env.invalid',
                message: $exception->getMessage(),
                sources: [$exception->source],
            );

            return $problems;
        }

        foreach ([[StatusLevel::Error, $result['critical_errors']], [StatusLevel::Warning, $result['warnings']]] as [$level, $entries]) {
            foreach ($entries as $entry) {
                $problems[] = new ConfigProblem(
                    level: $level,
                    type: $entry['type'],
                    message: $entry['message'],
                    sources: array_map($loader->sourceOf(...), $entry['variables']),
                );
            }
        }

        return $problems;
    }

    /**
     * Loaders that support it run without fallback, so a file the app would replace with
     * defaults throws instead of passing quietly.
     *
     * @return list<array{files:list<string>,load:Closure(string):ConfigLoadResult<object>}>
     */
    private function jsonChecks(): array
    {
        return [
            ['files' => [ServersConfigLoader::SERVERS_FILE, ServersConfigLoader::SECRETS_FILE], 'load' => (new ServersConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => [TtlConfigLoader::TTL_FILE], 'load' => (new TtlConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => [DataRetentionConfigLoader::DATA_RETENTION_FILE], 'load' => (new DataRetentionConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => [IntervalsConfigLoader::INTERVALS_FILE], 'load' => (new IntervalsConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => [ProbesConfigLoader::PROBES_FILE], 'load' => (new ProbesConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => [NotificationRulesConfigLoader::NOTIFICATION_RULES_FILE], 'load' => (new NotificationRulesConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => [ThresholdsConfigLoader::THRESHOLDS_FILE], 'load' => (new ThresholdsConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => [MenuConfigLoader::MENU_FILE], 'load' => (new MenuConfigLoader(MenuBuilder::MENU_KEYS, fallbackOnFailure: false))->load(...)],
            ['files' => [ChartsConfigLoader::CHARTS_FILE], 'load' => (new ChartsConfigLoader(new MetricCatalog(), new MetricQueryPolicy(), fallbackOnFailure: false))->load(...)],
            ['files' => [ChannelsConfigLoader::CHANNELS_FILE], 'load' => (new ChannelsConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => [AlarmRateLimitsConfigLoader::LIMITS_FILE], 'load' => (new AlarmRateLimitsConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => [AdvisoriesConfigLoader::ADVISORIES_FILE], 'load' => (new AdvisoriesConfigLoader(fallbackOnFailure: false))->load(...)],
        ];
    }

    /**
     * @param list<string>                              $files the file the loader reads first, then any companions
     * @param Closure(string): ConfigLoadResult<object> $load
     *
     * @return list<ConfigProblem>
     */
    private function validateJson(string $configPath, array $files, Closure $load): array
    {
        try {
            $loadProblems = $load($configPath)->problems;
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
        if ($rejected || (in_array($loadProblem->type, self::ERROR_TYPES, true) && $loadProblem->message !== ServersConfig::EMPTY_ERROR)) {
            return StatusLevel::Error;
        }

        return in_array($loadProblem->level, [LogLevel::CRITICAL, LogLevel::ERROR], true) ? StatusLevel::Error : StatusLevel::Warning;
    }
}
