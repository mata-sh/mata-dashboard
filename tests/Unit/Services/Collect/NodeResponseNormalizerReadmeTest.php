<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Collect;

use Mata\Services\Collect\NodeResponseNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Collect\NodeResponseNormalizer
 *
 * @internal
 */
final class NodeResponseNormalizerReadmeTest extends TestCase
{
    public function testNormalizeReadmeDataUsesContentField(): void
    {
        $normalizer = new NodeResponseNormalizer(new NullLogger());

        $response = $normalizer->normalizeApiResponse(
            data: ['content' => "# Test\n\nREADME"],
            path: 'test-app/readme',
        );

        $this->assertSame("# Test\n\nREADME", $response['result']);
    }

    public function testNormalizeReadmeDataPreservesAContextualNotice(): void
    {
        $normalizer = new NodeResponseNormalizer(new NullLogger());

        $response = $normalizer->normalizeApiResponse(
            data: [
                'content' => '',
                'notice' => [
                    'code' => 'README_NOT_FOUND',
                    'message' => 'README not found',
                ],
            ],
            path: 'test-app/readme',
        );

        $this->assertSame('', $response['result']);
        $this->assertSame([
            'code' => 'README_NOT_FOUND',
            'message' => 'README not found',
        ], $response['notice']);
    }
}
