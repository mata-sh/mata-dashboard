<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\DataTransformers;

use Mata\Services\DataTransformers\ApiResponseTransformer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\DataTransformers\ApiResponseTransformer
 *
 * @internal
 */
final class ApiResponseTransformerReadmeTest extends TestCase
{
    public function testNormalizeReadmeDataUsesContentField(): void
    {
        $transformer = new ApiResponseTransformer(new NullLogger());

        $response = $transformer->normalizeApiResponse(
            data: ['content' => "# Test\n\nREADME"],
            path: 'test-app/readme',
        );

        $this->assertSame("# Test\n\nREADME", $response['result']);
    }

    public function testNormalizeReadmeDataPreservesAContextualNotice(): void
    {
        $transformer = new ApiResponseTransformer(new NullLogger());

        $response = $transformer->normalizeApiResponse(
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
