<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use Closure;
use Mata\Config\EnvConfigLoader;
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
use Psr\Log\LogLevel;
use Throwable;

/**
 * Validates an instance config directory with the same loaders the web app and cron use, so
 * a file passes here exactly when the app would accept it. Problems the app would only log
 * become a report instead; files the app would replace with defaults wholesale are errors.
 */
final class InstanceConfigValidator
{
    public const ENV_FILES = ['config.env', 'db.config.env', 'mail.config.env'];

    /**
     * Reported types that mean the app ignores a whole file or a server, reported as errors:
     * the channels and limits loaders ignore their file without throwing, and every servers
     * validation problem drops that server (or its key). Other loaders throw instead.
     */
    private const ERROR_TYPES = ['channels_config_invalid', 'limits_config_invalid', 'servers_config_validation_error'];

    /** @var list<ConfigProblem> */
    private array $problems = [];

    /**
     * @param ?string $onlyFile validate only the files loaded together with this one: the three env
     *                          files, or servers.json with servers.secrets.json
     */
    public function validate(string $configPath, ?string $onlyFile = null): ConfigValidationReport
    {
        $this->problems = [];
        $configPath = rtrim($configPath, '/');
        $jsonChecks = $this->jsonChecks();

        $files = [];
        foreach ([self::ENV_FILES, ...array_column($jsonChecks, 'files')] as $checkFiles) {
            if ($onlyFile !== null && !in_array($onlyFile, $checkFiles, true)) {
                continue;
            }
            foreach ($checkFiles as $file) {
                $files[$file] = is_file($configPath . '/' . $file);
            }
        }

        // Env files always load: the JSON loaders read their variables (channels.json *_env keys).
        foreach ($this->validateEnv($configPath) as $problem) {
            if (array_key_exists($problem->file, $files)) {
                $this->problems[] = $problem;
            }
        }
        foreach ($jsonChecks as $check) {
            if ($onlyFile === null || in_array($onlyFile, $check['files'], true)) {
                $this->runJsonCheck($configPath, $check['files'], $check['load']);
            }
        }

        return new ConfigValidationReport($configPath, $files, $this->problems);
    }

    /** @return list<string> every file name `validate()` accepts as $onlyFile */
    public function knownFiles(): array
    {
        return [...self::ENV_FILES, ...array_merge(...array_column($this->jsonChecks(), 'files'))];
    }

    /** @return list<ConfigProblem> */
    private function validateEnv(string $configPath): array
    {
        $problems = [];
        foreach (self::ENV_FILES as $file) {
            if (!is_file($configPath . '/' . $file)) {
                $problems[] = new ConfigProblem($file, ConfigProblem::ERROR, 'config.file.missing', "{$file} is missing; copy it from config.example/{$file}.example");
            }
        }

        $loader = new EnvConfigLoader();

        try {
            $result = $loader->validateConfig($loader->load(self::ENV_FILES, $configPath));
        } catch (Throwable $exception) {
            // Dotenv parse errors and out-of-range values; neither names its file.
            $problems[] = new ConfigProblem('config.env', ConfigProblem::ERROR, 'config.env.invalid', $exception->getMessage());

            return $problems;
        }

        foreach ([ConfigProblem::ERROR => $result['critical_errors'], ConfigProblem::WARNING => $result['warnings']] as $level => $entries) {
            foreach ($entries as $entry) {
                $problems[] = new ConfigProblem($this->envFileFor($entry['identifier']), $level, $entry['identifier'], $entry['message']);
            }
        }

        return $problems;
    }

    /** Best guess at the env file a validateConfig() identifier belongs to; any file may set any variable. */
    private function envFileFor(string $identifier): string
    {
        return match (true) {
            str_starts_with($identifier, 'config.mail.') => 'mail.config.env',
            str_starts_with($identifier, 'config.database.') => 'db.config.env',
            default => 'config.env',
        };
    }

    /**
     * Loaders that support it run without fallback, so a file the app would replace with
     * defaults throws instead of passing quietly.
     *
     * @return list<array{files:list<string>,load:Closure(string):ConfigLoadResult<object>}>
     */
    private function jsonChecks(): array
    {
        $metricCatalog = new MetricCatalog();

        return [
            ['files' => ['servers.json', ServersConfigLoader::SECRETS_FILE], 'load' => (new ServersConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => ['ttl.json'], 'load' => (new TtlConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => ['data_retention.json'], 'load' => (new DataRetentionConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => ['intervals.json'], 'load' => (new IntervalsConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => ['probes.json'], 'load' => (new ProbesConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => ['notification_rules.json'], 'load' => (new NotificationRulesConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => ['thresholds.json'], 'load' => (new ThresholdsConfigLoader(fallbackOnFailure: false))->load(...)],
            ['files' => ['menu.json'], 'load' => (new MenuConfigLoader(MenuBuilder::MENU_KEYS, fallbackOnFailure: false))->load(...)],
            ['files' => ['charts.json'], 'load' => (new ChartsConfigLoader($metricCatalog, new MetricQueryPolicy(), fallbackOnFailure: false))->load(...)],
            ['files' => ['channels.json'], 'load' => (new ChannelsConfigLoader())->load(...)],
            ['files' => ['limits.json'], 'load' => (new AlarmRateLimitsConfigLoader())->load(...)],
        ];
    }

    /**
     * @param list<string>                              $files the file the loader reads first, then any companions
     * @param Closure(string): ConfigLoadResult<object> $load
     */
    private function runJsonCheck(string $configPath, array $files, Closure $load): void
    {
        try {
            $problems = $load($configPath)->problems;
            $rejected = false;
        } catch (MonitoringConfigLoadException $exception) {
            $problems = $exception->problems ?: [new ConfigLoadProblem('config_file_rejected', "{$files[0]} was rejected; the app would use defaults", LogLevel::ERROR)];
            $rejected = true;
        }

        foreach ($problems as $reported) {
            $problem = new ConfigProblem(
                $this->fileFor($reported->message, $files),
                in_array($reported->level, [LogLevel::CRITICAL, LogLevel::ERROR], true) ? ConfigProblem::ERROR : ConfigProblem::WARNING,
                $reported->type,
                $reported->message,
            );
            $isError = $rejected || (in_array($reported->type, self::ERROR_TYPES, true) && $reported->message !== ServersConfig::EMPTY_ERROR);
            $this->problems[] = $isError ? $problem->asError() : $problem;
        }
    }

    /**
     * Problems inside a companion file (servers.secrets.json) start with its name or quote its
     * path; a message that merely points to it ("move it to servers.secrets.json") does not.
     *
     * @param list<string> $files
     */
    private function fileFor(string $message, array $files): string
    {
        foreach (array_slice($files, 1) as $companion) {
            if (str_starts_with($message, $companion) || str_contains($message, '/' . $companion)) {
                return $companion;
            }
        }

        return $files[0];
    }
}
