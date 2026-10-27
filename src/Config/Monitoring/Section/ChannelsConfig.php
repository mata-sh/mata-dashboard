<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigValue;
use Mata\Config\Monitoring\ConfigObject;

/**
 * Alarm delivery channels from channels.json, next to the built-in email channel that
 * mail.config.env configures. An `email` channel mails its own recipients through the same
 * SMTP settings. Each webhook URL comes from the environment variable named by `url_env`,
 * because webhook URLs embed bearer secrets; messages name the variable, never its value.
 */
final readonly class ChannelsConfig
{
    public const EMAIL = 'email';
    private const WEBHOOK_KEYS = ['type', 'enabled', 'url_env', 'template', 'params', 'timeout_seconds'];
    private const EMAIL_KEYS = ['type', 'enabled', 'recipients'];

    /**
     * @param array<string,EmailChannel>   $emails   keyed by channel name
     * @param array<string,WebhookChannel> $webhooks keyed by channel name
     */
    private function __construct(
        public array $emails,
        public array $webhooks,
    ) {}

    public static function empty(): self
    {
        return new self([], []);
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
        if (!ConfigValue::isObject($entries)) {
            $reportInvalid('channels must be an object; only email is available.');

            return self::empty();
        }

        $allowInsecure = in_array(strtoupper((string) $env('WEBHOOK_ALLOW_INSECURE')), ['ON', 'TRUE', '1', 'YES'], true);
        $emails = [];
        $webhooks = [];
        foreach ($entries as $name => $entry) {
            try {
                $channel = self::channel((string) $name, $entry, $env, $allowInsecure);
            } catch (InvalidArgumentException $exception) {
                $reportInvalid($exception->getMessage() . ' Skipping channel.');

                continue;
            }
            if ($channel instanceof EmailChannel) {
                $emails[$channel->name] = $channel;
            } elseif ($channel instanceof WebhookChannel) {
                $webhooks[$channel->name] = $channel;
            }
        }

        return new self($emails, $webhooks);
    }

    /**
     * Every channel alarms can be routed to: the built-in email first, then the enabled email
     * channels, then the enabled webhooks.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return [self::EMAIL, ...array_keys($this->emails), ...array_keys($this->webhooks)];
    }

    /**
     * @param callable(string):?string $env
     *
     * @return null|EmailChannel|WebhookChannel null when the channel is disabled
     */
    private static function channel(string $name, mixed $value, callable $env, bool $allowInsecure): EmailChannel|WebhookChannel|null
    {
        $path = "channels.{$name}";
        if (!ConfigValue::isSlug($name, NotificationRulesConfig::CHANNEL_NAME_MAX_LENGTH) || $name === self::EMAIL) {
            throw new InvalidArgumentException("{$path}: invalid or reserved channel name.");
        }
        $entry = ConfigObject::fromValue($value, $path);

        return match ($entry->raw('type')) {
            'email' => self::email($name, $entry),
            'webhook' => self::webhook($name, $entry, $env, $allowInsecure),
            default => throw new InvalidArgumentException("{$entry->path('type')} must be \"email\" or \"webhook\"."),
        };
    }

    private static function email(string $name, ConfigObject $entry): ?EmailChannel
    {
        $entry->rejectUnknownKeys(self::EMAIL_KEYS);
        if (!$entry->boolean('enabled', true)) {
            return null;
        }

        $expected = 'a non-empty list of email addresses';
        $recipients = $entry->stringList('recipients', static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false, $expected);
        if ($recipients === []) {
            throw new InvalidArgumentException("{$entry->path('recipients')} must be {$expected}.");
        }

        return new EmailChannel($name, $recipients);
    }

    /** @param callable(string):?string $env */
    private static function webhook(string $name, ConfigObject $entry, callable $env, bool $allowInsecure): ?WebhookChannel
    {
        $path = $entry->path;
        $entry->rejectUnknownKeys(self::WEBHOOK_KEYS);
        if (!$entry->boolean('enabled', true)) {
            return null;
        }

        $urlEnv = $entry->envName('url_env');
        $template = $entry->raw('template');
        if (!is_string($template) || !ConfigValue::isSlug($template)) {
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
