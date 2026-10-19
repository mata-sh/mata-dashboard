<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use JsonException;
use Mata\Config\ConfigValueValidator;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use RuntimeException;
use stdClass;

/**
 * Edits servers.json and servers.secrets.json for the operator CLI.
 *
 * An edit locks the config directory for its whole read-modify-write, so concurrent edits queue
 * instead of losing one another's server. Each file is written to a temp file and renamed over
 * the old one, so the app never reads a half-written file; the temp file first gets the old
 * file's mode and group, through which the web server reads it (secrets 0640). A new secrets
 * file gets 0640 and the group of servers.json. Key order and indentation are kept, so a
 * versioned config repo sees a minimal diff.
 */
final class ServersConfigEditor
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private string $configPath) {}

    /**
     * Problems that did not stop the last write, e.g. a group that could not be set.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function serversPath(): string
    {
        return rtrim($this->configPath, '/') . '/servers.json';
    }

    private function secretsPath(): string
    {
        return rtrim($this->configPath, '/') . '/' . ServersConfigLoader::SECRETS_FILE;
    }

    /** @return array<string,mixed> servers.json, including `_`-prefixed documentation keys */
    public function readServers(): array
    {
        return $this->read($this->serversPath());
    }

    /** @return array<string,mixed> */
    public function readSecrets(): array
    {
        return is_file($this->secretsPath()) ? $this->read($this->secretsPath()) : [];
    }

    /**
     * Servers in servers.json without the `_` documentation keys, each with its key from
     * servers.secrets.json (null when missing). Entries stay raw: server list reports invalid ones.
     *
     * @return array<string,array{entry:mixed,apiKey:?string}>
     */
    public function entries(): array
    {
        $secrets = $this->readSecrets();
        $entries = [];
        foreach ($this->readServers() as $name => $entry) {
            $name = (string) $name;
            if (str_starts_with($name, '_')) {
                continue;
            }
            $secret = $secrets[$name] ?? null;
            $entries[$name] = ['entry' => $entry, 'apiKey' => is_array($secret) && is_string($secret['api_key'] ?? null) ? $secret['api_key'] : null];
        }

        return $entries;
    }

    /** @param array<string,mixed> $entry */
    public function add(string $name, array $entry, string $apiKey): void
    {
        $this->locked(function () use ($name, $entry, $apiKey): void {
            $servers = is_file($this->serversPath()) ? $this->readServers() : [];
            $secrets = $this->readSecrets();
            if (array_key_exists($name, $servers)) {
                throw new RuntimeException("Server '{$name}' already exists in servers.json");
            }
            $servers[$name] = $entry;
            $secrets[$name] = ['api_key' => $apiKey];

            // Secrets first: a server without its key is dropped, a key without its server only warns.
            $this->replace([
                $this->secretsPath() => [$secrets, 0640],
                $this->serversPath() => [$servers, 0644],
            ]);
        });
    }

    /** @return bool whether the server existed in either file */
    public function remove(string $name): bool
    {
        return $this->locked(function () use ($name): bool {
            $servers = is_file($this->serversPath()) ? $this->readServers() : [];
            $secrets = $this->readSecrets();
            // Server first, for the same reason as in add().
            $changes = [];
            if (array_key_exists($name, $servers)) {
                unset($servers[$name]);
                $changes[$this->serversPath()] = [$servers, 0644];
            }
            if (array_key_exists($name, $secrets)) {
                unset($secrets[$name]);
                $changes[$this->secretsPath()] = [$secrets, 0640];
            }
            $this->replace($changes);

            return $changes !== [];
        });
    }

    /**
     * Runs $edit holding an exclusive lock on the config directory. The directory, not a file:
     * replace() swaps the files, and a lock on a replaced file guards nothing.
     *
     * @template T
     *
     * @param callable(): T $edit
     *
     * @return T
     */
    private function locked(callable $edit): mixed
    {
        $this->warnings = [];
        $handle = @fopen($this->configPath, 'r');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException("Cannot lock {$this->configPath}");
        }

        try {
            return $edit();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    private function read(string $path): array
    {
        $content = is_readable($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in {$path}: {$exception->getMessage()}", previous: $exception);
        }
        if (!ConfigValueValidator::isObject($data)) {
            throw new RuntimeException("{$path} must contain a JSON object");
        }

        return $data;
    }

    /**
     * Writes every file to a temp file next to it, then renames them over the originals in
     * order. A failure while writing changes nothing; a failed rename names what was replaced.
     *
     * @param array<string,array{0:array<string,mixed>,1:int}> $changes path => [data, mode of a new file]
     */
    private function replace(array $changes): void
    {
        $staged = [];

        try {
            foreach ($changes as $path => [$data, $newFileMode]) {
                $staged[$path] = $this->stage($path, $data, $newFileMode);
            }
            $replaced = [];
            foreach ($staged as $path => $temp) {
                if (!@rename($temp, $path)) {
                    throw new RuntimeException("Cannot replace {$path}" . ($replaced === [] ? '' : '; already replaced: ' . implode(', ', $replaced)));
                }
                unset($staged[$path]);
                $replaced[] = $path;
            }
        } finally {
            foreach ($staged as $temp) {
                @unlink($temp);
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return string the temp file, with the mode and group $path has or should get
     */
    private function stage(string $path, array $data, int $newFileMode): string
    {
        $exists = is_file($path);
        $indent = $exists ? $this->detectIndent((string) file_get_contents($path)) : 2;
        $json = json_encode($data === [] ? new stdClass() : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($indent !== 4) {
            $json = (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 4) * $indent), $json);
        }

        // Hidden, so a config repo's `git status` during the edit stays quiet.
        $temp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $umask = umask(0077);

        try {
            $written = file_put_contents($temp, $json . "\n", LOCK_EX);
        } finally {
            umask($umask);
        }
        if ($written === false) {
            @unlink($temp);

            throw new RuntimeException("Cannot write {$temp}");
        }

        $mode = $exists ? fileperms($path) & 0777 : $newFileMode;
        // Same group as before; a new file takes the group of servers.json, through which the web server reads the config.
        $groupSource = $exists ? $path : $this->serversPath();
        $group = is_file($groupSource) ? filegroup($groupSource) : false;
        chmod($temp, $mode);
        if ($group !== false && $group !== filegroup($temp) && !@chgrp($temp, $group)) {
            $this->warnings[] = "Could not give {$path} its group ({$group}); make it readable by the web server's group (0640), or the servers load fails";
        }
        if ($exists && fileowner($path) !== fileowner($temp)) {
            $this->warnings[] = "{$path} now belongs to the account that ran this command instead of its previous owner";
        }

        return $temp;
    }

    private function detectIndent(string $json): int
    {
        return preg_match('/^( +)"/m', $json, $match) === 1 ? strlen($match[1]) : 2;
    }
}
