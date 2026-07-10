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
}
