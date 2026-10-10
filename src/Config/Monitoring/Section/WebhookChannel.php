<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use SensitiveParameter;

/** One webhook alarm channel. The URL embeds a bearer secret and must never reach logs or errors. */
final readonly class WebhookChannel
{
    /** @param array<string,scalar> $params template variables, e.g. a Telegram chat_id */
    public function __construct(
        public string $name,
        #[SensitiveParameter]
        public string $url,
        public string $template,
        public array $params,
        public int $timeoutSeconds,
    ) {}
}
