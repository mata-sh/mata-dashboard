<?php

declare(strict_types=1);

namespace Mata\Services\ServerConfig;

final readonly class ServerConnectionResult
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

    public function isOk(): bool
    {
        return $this->health === 'healthy' && $this->auth === 'ok';
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
