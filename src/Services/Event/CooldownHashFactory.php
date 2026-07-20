<?php

declare(strict_types=1);

namespace Mata\Services\Event;

use Mata\Services\Event\Dto\EventContext;

/** Creates deterministic hashes from canonical cooldown identities. */
final class CooldownHashFactory
{
    /** Create a cooldown hash for an event. */
    public function forEvent(
        string $type,
        int|string|null $server,
        int|string|null $app,
        EventContext $context,
    ): string {
        return $this->hash($type, $server, $app, $context, '');
    }

    /** Create a cooldown hash for an alarm delivery channel. */
    public function forAlarmChannel(
        string $type,
        int|string|null $server,
        int|string|null $app,
        EventContext $context,
        string $channel,
    ): string {
        return $this->hash($type, $server, $app, $context, $channel);
    }

    private function hash(
        string $type,
        int|string|null $server,
        int|string|null $app,
        EventContext $context,
        string $channel,
    ): string {
        $context = $context->forCooldownIdentity();
        $encoded = json_encode([
            'type' => $type,
            'server' => $server === null ? '' : (string) $server,
            'app' => $app === null ? '' : (string) $app,
            'context' => $context->toJson(),
            'channel' => $channel,
        ], JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : hash('sha256', $encoded);
    }
}
