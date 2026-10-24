<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use JsonSerializable;
use Mata\Services\Status\StatusLevel;

final readonly class NodeConnectionResult implements JsonSerializable
{
    public function __construct(
        public string $server,
        public string $apiUrl,
        public NodeHealthState $health,
        public ?string $healthError,
        public NodeAuthState $auth,
        public ?string $authError = null,
        public ?string $hint = null,
    ) {}

    /** A rejected or untested key is an error; an authenticated node that is not healthy a warning. */
    public function level(): StatusLevel
    {
        return match (true) {
            $this->auth !== NodeAuthState::Ok => StatusLevel::Error,
            $this->health !== NodeHealthState::Healthy => StatusLevel::Warning,
            default => StatusLevel::Ok,
        };
    }

    public function isOk(): bool
    {
        return $this->level() === StatusLevel::Ok;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'server' => $this->server,
            'api_url' => $this->apiUrl,
            'health' => $this->health->value,
            'health_error' => $this->healthError,
            'auth' => $this->auth->value,
            'auth_error' => $this->authError,
            'hint' => $this->hint,
            'ok' => $this->isOk(),
        ];
    }
}
