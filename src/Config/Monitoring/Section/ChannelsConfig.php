<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigObject;
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
    private static function webhook(string $name, mixed $value, callable $env, bool $allowInsecure): ?WebhookChannel
    {
        $path = "channels.{$name}";
        if (!ConfigValueValidator::isSlug($name, NotificationRulesConfig::CHANNEL_NAME_MAX_LENGTH) || $name === self::EMAIL) {
            throw new InvalidArgumentException("{$path}: invalid or reserved channel name.");
        }
        $entry = ConfigObject::of($value, $path);
        if ($entry->raw('type') !== 'webhook') {
            throw new InvalidArgumentException("{$entry->path('type')} must be \"webhook\".");
        }
        $entry->rejectUnknownKeys(self::WEBHOOK_KEYS);
        if (!$entry->boolean('enabled', true)) {
            return null;
        }

        $urlEnv = $entry->envName('url_env');
        $template = $entry->raw('template');
        if (!is_string($template) || !ConfigValueValidator::isSlug($template)) {
            throw new InvalidArgumentException("{$entry->path('template')} must name a template in templates/webhooks/.");
        }
        $timeout = $entry->integer('timeout_seconds', 5, 1, 30);
        $params = $entry->object('params')->values;
        if (array_filter($params, static fn (mixed $param): bool => !is_scalar($param)) !== []) {
            throw new InvalidArgumentException("{$entry->path('params')} must be an object of strings, numbers or booleans.");
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
