<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Database;

use Mata\Database\SqlQueryClassifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Database\SqlQueryClassifier
 *
 * @internal
 */
final class SqlQueryClassifierTest extends TestCase
{
    public function testItIgnoresLeadingWhitespace(): void
    {
        self::assertSame('SELECT', SqlQueryClassifier::leadingKeyword("\n\t SELECT * FROM users"));
    }

    public function testItNormalizesKeywordCase(): void
    {
        self::assertSame('INSERT', SqlQueryClassifier::leadingKeyword('iNsErT INTO users VALUES (1)'));
    }

    public function testItReturnsEmptyStringWithoutLeadingKeyword(): void
    {
        self::assertSame('', SqlQueryClassifier::leadingKeyword('123 SELECT * FROM users'));
    }
}
