<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use Mata\Services\Status\StatusLevel;

final readonly class NodeConnectionResult
{
    /** @param 'failed'|'ok'|'skipped' $auth */
    public function __construct(
        public string $server,
        public string $apiUrl,
        public string $health,
        public ?string $healthError,
        public string $auth,
        public ?string $authError = null,
        public ?string $hint = null,
    ) {}

    /** A rejected or untested key is an error; an authenticated node that is not healthy a warning. */
    public function level(): StatusLevel
    {
        return match (true) {
            $this->auth !== 'ok' => StatusLevel::Error,
            $this->health !== 'healthy' => StatusLevel::Warning,
            default => StatusLevel::Ok,
        };
    }

    public function isOk(): bool
    {
        return $this->level() === StatusLevel::Ok;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'server' => $this->server,
            'api_url' => $this->apiUrl,
            'health' => $this->health,
            'health_error' => $this->healthError,
            'auth' => $this->auth,
            'auth_error' => $this->authError,
            'hint' => $this->hint,
            'ok' => $this->isOk(),
        ];
    }
}
