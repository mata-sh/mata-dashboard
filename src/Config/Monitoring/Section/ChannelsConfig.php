<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigValueValidator;

/**
 * Alarm delivery channels from channels.json, next to the built-in email channel that
 * mail.config.env configures. `webhook` is the only configurable type. Each webhook URL comes
 * from the environment variable named by `url_env`, because webhook URLs embed bearer
 * secrets; messages name the variable, never its value.
 */
final readonly class ChannelsConfig
{
    public const EMAIL = 'email';
    private const ENV_NAME_PATTERN = '/^[A-Z][A-Z0-9_]*$/';
    private const TEMPLATE_NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';
    private const WEBHOOK_KEYS = ['type', 'enabled', 'url_env', 'template', 'params', 'timeout_seconds'];

    /** @param array<string,WebhookChannel> $webhooks keyed by channel name */
    private function __construct(public array $webhooks) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Invalid channels are reported and skipped independently; disabled ones are skipped silently.
     *
     * @param array<string,mixed>      $raw
     * @param callable(string):?string $env
     * @param callable(string):void    $reportInvalid
     */
    public static function fromArray(array $raw, callable $env, callable $reportInvalid): self
    {
        $entries = $raw['channels'] ?? [];
        if (!ConfigValueValidator::isObject($entries)) {
            $reportInvalid('channels must be an object; only email is available.');

            return self::empty();
        }

        $allowInsecure = in_array(strtoupper((string) $env('WEBHOOK_ALLOW_INSECURE')), ['ON', 'TRUE', '1', 'YES'], true);
        $webhooks = [];
        foreach ($entries as $name => $entry) {
            try {
                $webhook = self::webhook((string) $name, $entry, $env, $allowInsecure);
            } catch (InvalidArgumentException $exception) {
                $reportInvalid($exception->getMessage() . ' Skipping channel.');

                continue;
            }
            if ($webhook !== null) {
                $webhooks[$webhook->name] = $webhook;
            }
        }

        return new self($webhooks);
    }

    /**
     * Every channel alarms can be routed to: email first, then the enabled webhooks.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return [self::EMAIL, ...array_keys($this->webhooks)];
    }

    /** @param callable(string):?string $env */
    private static function webhook(string $name, mixed $entry, callable $env, bool $allowInsecure): ?WebhookChannel
    {
        $path = "channels.{$name}";
        if (preg_match(NotificationRulesConfig::CHANNEL_NAME_PATTERN, $name) !== 1 || $name === self::EMAIL) {
            throw new InvalidArgumentException("{$path}: invalid or reserved channel name.");
        }
        if (!ConfigValueValidator::isObject($entry)) {
            throw new InvalidArgumentException("{$path} must be an object.");
        }
        if (($entry['type'] ?? null) !== 'webhook') {
            throw new InvalidArgumentException("{$path}.type must be \"webhook\".");
        }
        $unknown = array_diff(array_keys($entry), self::WEBHOOK_KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$path} contains unknown key " . reset($unknown) . '.');
        }

        $enabled = $entry['enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException("{$path}.enabled must be a boolean.");
        }
        if (!$enabled) {
            return null;
        }

        $urlEnv = $entry['url_env'] ?? null;
        if (!is_string($urlEnv) || preg_match(self::ENV_NAME_PATTERN, $urlEnv) !== 1) {
            throw new InvalidArgumentException("{$path}.url_env must name an environment variable.");
        }
        $template = $entry['template'] ?? null;
        if (!is_string($template) || preg_match(self::TEMPLATE_NAME_PATTERN, $template) !== 1) {
            throw new InvalidArgumentException("{$path}.template must name a template in templates/webhooks/.");
        }
        $timeout = $entry['timeout_seconds'] ?? 5;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 30) {
            throw new InvalidArgumentException("{$path}.timeout_seconds must be an integer from 1 to 30.");
        }
        $params = $entry['params'] ?? [];
        if (!ConfigValueValidator::isObject($params) || array_filter($params, static fn (mixed $value): bool => !is_scalar($value)) !== []) {
            throw new InvalidArgumentException("{$path}.params must be an object of strings, numbers or booleans.");
        }

        $url = trim((string) $env($urlEnv));
        if ($url === '') {
            throw new InvalidArgumentException("{$path}: environment variable {$urlEnv} is not set.");
        }
        $parts = parse_url($url) ?: [];
        $scheme = strtolower($parts['scheme'] ?? '');
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException("{$path}: {$urlEnv} must be an absolute http(s) URL without userinfo.");
        }
        if ($scheme === 'http' && !$allowInsecure) {
            throw new InvalidArgumentException("{$path}: {$urlEnv} must use https; set WEBHOOK_ALLOW_INSECURE=true to allow http.");
        }

        return new WebhookChannel($name, $url, $template, $params, $timeout);
    }
}
