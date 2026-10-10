<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Collect;

use Mata\Services\Collect\HmacAuthTokenGenerator;
use Mata\Tests\TestCase;

/**
 * The token format is the contract mata-node verifies: server|timestamp|nonce|signature.
 *
 * @covers \Mata\Services\Collect\HmacAuthTokenGenerator
 *
 * @internal
 */
final class HmacAuthTokenGeneratorTest extends TestCase
{
    public function testItBuildsTheTokenNodesVerify(): void
    {
        $server = 'node-1';
        $apiKey = 'test-api-key';
        $timestamp = 1700000000;
        $nonce = 'nonce-123';
        $payload = $server . '|' . $timestamp . '|' . $nonce;
        $signature = hash_hmac('sha256', $payload, $apiKey);

        $token = (new HmacAuthTokenGenerator())->generate($server, $apiKey, $timestamp, $nonce);

        $this->assertSame($payload . '|' . $signature, $token);
    }
}
