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
 * Files are rewritten in place, so owner, group and mode survive: the web server reads them
 * through their group (secrets 0640), and a replaced file would lose that. A new secrets file
 * gets 0640 and the group of servers.json. Key order and indentation are kept, so a versioned
 * config repo sees a minimal diff.
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

    public function serversPath(): string
    {
        return rtrim($this->configPath, '/') . '/servers.json';
    }

    public function secretsPath(): string
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
        $this->warnings = [];
        $servers = is_file($this->serversPath()) ? $this->readServers() : [];
        $secrets = $this->readSecrets();
        if (array_key_exists($name, $servers)) {
            throw new RuntimeException("Server '{$name}' already exists in servers.json");
        }
        $servers[$name] = $entry;
        $secrets[$name] = ['api_key' => $apiKey];

        // Secrets first: a server without its key is dropped, a key without its server only warns.
        $this->write($this->secretsPath(), $secrets, newFileMode: 0640);
        $this->write($this->serversPath(), $servers, newFileMode: 0644);
    }

    /** @return bool whether the server existed in either file */
    public function remove(string $name): bool
    {
        $servers = is_file($this->serversPath()) ? $this->readServers() : [];
        $secrets = $this->readSecrets();
        $this->warnings = [];
        $found = array_key_exists($name, $servers) || array_key_exists($name, $secrets);
        if (array_key_exists($name, $servers)) {
            unset($servers[$name]);
            $this->write($this->serversPath(), $servers, newFileMode: 0644);
        }
        if (array_key_exists($name, $secrets)) {
            unset($secrets[$name]);
            $this->write($this->secretsPath(), $secrets, newFileMode: 0640);
        }

        return $found;
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

    /** @param array<string,mixed> $data */
    private function write(string $path, array $data, int $newFileMode): void
    {
        $exists = is_file($path);
        $indent = $exists ? $this->detectIndent((string) file_get_contents($path)) : 2;
        $json = json_encode($data === [] ? new stdClass() : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($indent !== 4) {
            $json = (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 4) * $indent), $json);
        }

        if (!$exists) {
            $this->createFile($path, $newFileMode);
        }
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException("Cannot write {$path}");
        }

        try {
            if (!ftruncate($handle, 0) || fwrite($handle, $json . "\n") === false) {
                throw new RuntimeException("Cannot write {$path}");
            }
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function createFile(string $path, int $mode): void
    {
        $umask = umask(0077);

        try {
            if (file_put_contents($path, '') === false) {
                throw new RuntimeException("Cannot create {$path}");
            }
        } finally {
            umask($umask);
        }
        chmod($path, $mode);
        // Same group as servers.json, through which the web server reads the config.
        $group = is_file($this->serversPath()) ? filegroup($this->serversPath()) : false;
        if ($group !== false && !@chgrp($path, $group)) {
            $this->warnings[] = "Could not give {$path} the group of servers.json; make it readable by the web server's group (0640), or the servers load fails";
        }
    }

    private function detectIndent(string $json): int
    {
        return preg_match('/^( +)"/m', $json, $match) === 1 ? strlen($match[1]) : 2;
    }
}
