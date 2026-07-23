<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

/**
 * Generates one-time HMAC tokens for dashboard-to-node requests.
 */
final class HmacAuthTokenGenerator
{
    public function generate(
        string $server,
        string $apiKey,
        ?int $timestamp = null,
        ?string $nonce = null
    ): string {
        $timestampValue = $timestamp ?? time();
        $nonceValue = $nonce ?? bin2hex(random_bytes(16));
        $payload = $server . '|' . $timestampValue . '|' . $nonceValue;
        $signature = hash_hmac('sha256', $payload, $apiKey);

        return $payload . '|' . $signature;
    }
}
