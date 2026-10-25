<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use Closure;
use FilesystemIterator;
use Mata\Config\ConfigSource;
use Mata\Config\Dto\AlarmDecisionConfig;
use Mata\Config\Dto\OpaAlarmDecisionConfig;
use Mata\Services\Status\StatusLevel;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Checks the local Rego policies in a config directory against the alarm decision settings.
 * OPA merges them with the shipped policy/ into one namespace, and a local file that declares a
 * shipped package (a copied alarms.rego) stops OPA from loading any policy. Requiring packages
 * under `local` rules that out. Only package names are read; `make opa-test` compiles the policies.
 */
final class LocalPolicyValidator
{
    /** Local Rego policies, relative to the config path; OPA loads them next to the shipped policy/. */
    public const LOCAL_POLICY_DIR = 'policy';

    /**
     * @param Closure(string): ConfigSource $sourceOf where an env variable was set
     *
     * @return list<ConfigProblem>
     */
    public function validate(string $configPath, AlarmDecisionConfig $alarmDecision, Closure $sourceOf): array
    {
        $files = $this->policyFiles($configPath);

        $problems = [];
        foreach ($files as $file) {
            $package = $this->packageOf($configPath . '/' . $file);
            if ($package === 'local' || str_starts_with($package ?? '', 'local.')) {
                continue;
            }

            $declared = $package === null ? 'no package' : "package {$package}";
            $problems[] = new ConfigProblem(
                level: StatusLevel::Error,
                type: 'config.policy.invalid',
                message: "{$file} declares {$declared}; use a package under local, such as local.alarms. Outside local it can collide with a shipped package, and a collision stops OPA from loading any policy",
                sources: [ConfigSource::file($file)],
            );
        }

        $hasLocalPolicy = array_filter($files, static fn (string $file): bool => !str_ends_with($file, '_test.rego')) !== [];
        $decisionProblem = $this->decisionPathProblem($alarmDecision, $hasLocalPolicy, $sourceOf);
        if ($decisionProblem !== null) {
            $problems[] = $decisionProblem;
        }

        return $problems;
    }

    /**
     * Warns when local policies and the configured alarm decision disagree.
     *
     * @param Closure(string): ConfigSource $sourceOf
     */
    private function decisionPathProblem(AlarmDecisionConfig $alarmDecision, bool $hasLocalPolicy, Closure $sourceOf): ?ConfigProblem
    {
        if ($alarmDecision->driver !== 'opa') {
            return $hasLocalPolicy ? new ConfigProblem(
                level: StatusLevel::Warning,
                type: 'config.policy.unused',
                message: self::LOCAL_POLICY_DIR . "/ has local policies, but ALARM_DECISION_DRIVER={$alarmDecision->driver} ignores them",
                sources: [$sourceOf('ALARM_DECISION_DRIVER')],
            ) : null;
        }

        $opa = $alarmDecision->opa;
        if ($hasLocalPolicy && !$opa->queriesLocalPolicy()) {
            return new ConfigProblem(
                level: StatusLevel::Warning,
                type: 'config.policy.unused',
                message: self::LOCAL_POLICY_DIR . "/ has local policies, but OPA_ALARM_DECISION_PATH={$opa->decisionPath} does not query them; set it to the local decision, such as " . OpaAlarmDecisionConfig::LOCAL_DECISION_PREFIX . 'alarms/decision',
                sources: [$sourceOf('OPA_ALARM_DECISION_PATH')],
            );
        }
        if (!$hasLocalPolicy && $opa->queriesLocalPolicy()) {
            return new ConfigProblem(
                level: StatusLevel::Warning,
                type: 'config.policy.missing',
                message: "OPA_ALARM_DECISION_PATH={$opa->decisionPath} queries a local policy, but " . self::LOCAL_POLICY_DIR . '/ has none; every alarm evaluation would fail',
                sources: [$sourceOf('OPA_ALARM_DECISION_PATH')],
            );
        }

        return null;
    }

    /** The package a Rego file declares; null when it declares none. */
    private function packageOf(string $path): ?string
    {
        $source = (string) file_get_contents($path);

        return preg_match('/^\s*package\s+(\S+)/m', $source, $match) === 1 ? $match[1] : null;
    }

    /** @return list<string> .rego files below LOCAL_POLICY_DIR, relative to the config path */
    private function policyFiles(string $configPath): array
    {
        $dir = $configPath . '/' . self::LOCAL_POLICY_DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'rego') {
                $files[] = self::LOCAL_POLICY_DIR . substr($entry->getPathname(), strlen($dir));
            }
        }
        sort($files);

        return $files;
    }
}
