<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigObject;
use Mata\Config\ConfigValueValidator;
use Mata\Config\Monitoring\Section\Advisory\AdvisoryDigestConfig;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySourceType;
use Mata\Config\Monitoring\Section\Advisory\GithubRepository;
use Mata\Config\Monitoring\Section\Advisory\ImapEncryption;
use Mata\Config\Monitoring\Section\Advisory\ImapMailbox;
use Mata\Config\Monitoring\Section\Advisory\OsvPackage;
use Mata\Services\Audit\Severity;

/**
 * Security advisory sources from advisories.json. Without the file, or without sources in it,
 * the feature is off: no polling, no menu entry. Invalid sources are reported and skipped independently; disabled
 * ones are kept so the dashboard can list them. Secrets (GitHub token, IMAP credentials) come
 * from the environment variables the `*_env` keys name; messages name the variable only.
 */
final readonly class AdvisoriesConfig
{
    private const SOURCE_KEY_MAX_LENGTH = 64;
    private const DEFAULT_FAILURE_WARNING_AFTER = 3;
    private const DEFAULT_STALE_AFTER_MINUTES = 360;
    private const ROOT_KEYS = ['_comment', 'poll_interval_minutes', 'failure_warning_after', 'stale_after_minutes', 'digest', 'sources'];
    private const COMMON_SOURCE_KEYS = ['_comment', 'name', 'description', 'type', 'enabled', 'poll_interval_minutes', 'filters', 'notify'];
    private const FILTER_KEYS = ['keywords', 'min_severity'];

    /** @param array<string,AdvisorySource> $sources keyed by source key, in file order */
    private function __construct(
        public bool $configured,
        public array $sources,
        public int $failureWarningAfter,
        public int $staleAfterMinutes,
        public AdvisoryDigestConfig $digest,
    ) {}

    public static function empty(): self
    {
        return new self(
            configured: false,
            sources: [],
            failureWarningAfter: self::DEFAULT_FAILURE_WARNING_AFTER,
            staleAfterMinutes: self::DEFAULT_STALE_AFTER_MINUTES,
            digest: AdvisoryDigestConfig::disabled(),
        );
    }

    /**
     * @param array<string,mixed>      $raw
     * @param callable(string):?string $env
     * @param callable(string):void    $reportInvalid
     *
     * @throws InvalidArgumentException when a top-level setting is invalid, rejecting the file
     */
    public static function fromArray(array $raw, callable $env, callable $reportInvalid): self
    {
        $advisories = ConfigObject::fromValue($raw, 'advisories');
        $advisories->rejectUnknownKeys(self::ROOT_KEYS);
        $defaultInterval = $advisories->integer('poll_interval_minutes', 60, 5, 10080);
        $failureWarningAfter = $advisories->integer('failure_warning_after', self::DEFAULT_FAILURE_WARNING_AFTER, 1, 100);
        $staleAfterMinutes = $advisories->integer('stale_after_minutes', self::DEFAULT_STALE_AFTER_MINUTES, 15, 43200);
        // Without a `digest` key no digest is mailed; `"digest": {}` mails it with the defaults.
        $digest = $advisories->hasKey('digest')
            ? AdvisoryDigestConfig::fromConfig($advisories->object('digest'))
            : AdvisoryDigestConfig::disabled();
        $entries = $advisories->object('sources')->values;

        $sources = [];
        foreach ($entries as $key => $entry) {
            $key = (string) $key;

            try {
                $sources[$key] = self::parseSource($key, $entry, $defaultInterval, $env);
            } catch (InvalidArgumentException $exception) {
                $reportInvalid($exception->getMessage() . ' Skipping source.');
            }
        }

        // Listing sources turns the feature on, even when all of them are invalid: the page
        // then says so instead of disappearing. An empty file means defaults, i.e. off.
        return new self($entries !== [], $sources, $failureWarningAfter, $staleAfterMinutes, $digest);
    }

    /** @return array<string,AdvisorySource> */
    public function enabledSources(): array
    {
        return array_filter($this->sources, static fn (AdvisorySource $source): bool => $source->enabled);
    }

    public function source(string $key): ?AdvisorySource
    {
        return $this->sources[$key] ?? null;
    }

    /** @param callable(string):?string $env */
    private static function parseSource(string $key, mixed $value, int $defaultInterval, callable $env): AdvisorySource
    {
        $path = "advisories.sources.{$key}";
        if (!ConfigValueValidator::isSlug($key, self::SOURCE_KEY_MAX_LENGTH)) {
            throw new InvalidArgumentException("{$path}: the key must be lowercase letters, digits, '-' or '_' (at most " . self::SOURCE_KEY_MAX_LENGTH . ').');
        }
        $entry = ConfigObject::fromValue($value, $path);
        $type = self::sourceType($entry);
        $entry->rejectUnknownKeys([...self::COMMON_SOURCE_KEYS, ...$type->settingKeys()]);

        $name = $entry->text('name', $key, 80);
        $description = $entry->text('description', '', 500, allowEmpty: true);
        $enabled = $entry->boolean('enabled', true);
        $interval = $entry->integer('poll_interval_minutes', $defaultInterval, 5, 10080);
        [$keywords, $minSeverity] = self::filters($entry->object('filters'));
        $notify = $entry->boolean('notify', true);

        $github = $type === AdvisorySourceType::Github ? self::githubRepository($entry, $enabled, $env) : null;
        $mailbox = $type === AdvisorySourceType::Imap ? self::imapMailbox($entry, $enabled, $env) : null;
        $package = $type === AdvisorySourceType::Osv ? self::osvPackage($entry) : null;

        // GitHub, IMAP and OSV sources derive their URL from their settings; feed and CSAF sources configure it.
        $url = $github?->apiUrl() ?? $mailbox?->displayUrl() ?? $package?->pageUrl() ?? self::httpsUrl($entry, 'url');

        return new AdvisorySource(
            key: $key,
            name: $name,
            description: $description,
            type: $type,
            url: $url,
            enabled: $enabled,
            pollIntervalMinutes: $interval,
            keywords: $keywords,
            minSeverity: $minSeverity,
            notify: $notify,
            github: $github,
            mailbox: $mailbox,
            package: $package,
        );
    }

    private static function sourceType(ConfigObject $entry): AdvisorySourceType
    {
        $type = $entry->raw('type');

        return (is_string($type) ? AdvisorySourceType::tryFrom($type) : null)
            ?? throw new InvalidArgumentException("{$entry->path('type')} must be \"feed\", \"github\", \"imap\", \"osv\" or \"csaf\".");
    }

    /**
     * The token is optional; when `token_env` names one, a disabled source does not require it to be set,
     * since disabled sources are listed but never polled.
     *
     * @param callable(string):?string $env
     */
    private static function githubRepository(ConfigObject $entry, bool $enabled, callable $env): GithubRepository
    {
        $repository = $entry->raw('repository');
        if (!is_string($repository) || !GithubRepository::isValidName($repository)) {
            throw new InvalidArgumentException("{$entry->path('repository')} must be \"owner/name\".");
        }

        $token = null;
        if ($entry->hasKey('token_env')) {
            $tokenEnv = $entry->envName('token_env');
            $token = trim((string) $env($tokenEnv));
            if ($token === '' && $enabled) {
                throw new InvalidArgumentException("{$entry->path}: environment variable {$tokenEnv} is not set.");
            }
        }

        return new GithubRepository($repository, $token === '' ? null : $token);
    }

    /**
     * Disabled sources are listed but never polled, so their credentials are not required.
     *
     * @param callable(string):?string $env
     */
    private static function imapMailbox(ConfigObject $entry, bool $enabled, callable $env): ImapMailbox
    {
        $host = $entry->raw('host');
        if (!is_string($host) || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException("{$entry->path('host')} must be a host name.");
        }

        $encryption = $entry->raw('encryption', ImapEncryption::Ssl->value);
        $encryption = is_string($encryption) ? ImapEncryption::tryFrom($encryption) : null;
        if ($encryption === null) {
            throw new InvalidArgumentException("{$entry->path('encryption')} must be \"ssl\" or \"starttls\"; credentials are never sent unencrypted.");
        }
        
        $addresses = $entry->stringList('addresses', self::isMailAddressOrListId(...), 'a list of addresses or list IDs');

        $credentials = [];
        foreach (['username_env', 'password_env'] as $credentialKey) {
            $variable = $entry->envName($credentialKey);
            $value = (string) $env($variable);
            if ($value === '' && $enabled) {
                throw new InvalidArgumentException("{$entry->path}: environment variable {$variable} is not set.");
            }
            $credentials[] = $value;
        }

        return new ImapMailbox(
            host: $host,
            port: $entry->integer('port', $encryption->defaultPort(), 1, 65535),
            encryption: $encryption,
            username: $credentials[0],
            password: $credentials[1],
            folder: $entry->text('folder', 'INBOX', 200),
            addresses: array_map('strtolower', $addresses),
        );
    }

    private static function osvPackage(ConfigObject $entry): OsvPackage
    {
        // OSV rejects an unknown ecosystem with "invalid ecosystem", so a typo fails the poll visibly.
        return new OsvPackage(
            ecosystem: $entry->text('ecosystem', null, 64),
            name: $entry->text('package', null, 200),
        );
    }

    /** @return array{0:list<string>,1:Severity} */
    private static function filters(ConfigObject $filters): array
    {
        $filters->rejectUnknownKeys(self::FILTER_KEYS);
        $keywords = $filters->stringList('keywords', self::isKeyword(...), 'a list of non-empty words');

        $minSeverity = $filters->raw('min_severity', 'low');
        $severity = is_string($minSeverity) ? Severity::tryFrom($minSeverity) : null;
        if ($severity === null || $severity === Severity::Unknown) {
            throw new InvalidArgumentException("{$filters->path('min_severity')} must be \"low\", \"medium\", \"high\" or \"critical\".");
        }

        return [array_values(array_unique(array_map('trim', $keywords))), $severity];
    }

    private static function httpsUrl(ConfigObject $entry, string $key): string
    {
        $url = $entry->raw($key);
        $parts = is_string($url) ? (parse_url($url) ?: []) : [];
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException("{$entry->path($key)} must be an absolute https URL without userinfo.");
        }

        return $url;
    }

    private static function isMailAddressOrListId(string $value): bool
    {
        return preg_match('/^[^\s<>]{3,254}$/', $value) === 1;
    }

    private static function isKeyword(string $value): bool
    {
        return trim($value) !== '' && mb_strlen($value) <= 100;
    }
}
