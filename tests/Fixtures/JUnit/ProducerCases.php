<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\JUnit;

use PHPUnit\Framework\TestCase;

/**
 * Deliberate native-reporter input, invoked explicitly outside application test discovery.
 * Its failure, error and skip are controlled fixture outcomes, not project defects.
 */
final class ProducerCases extends TestCase
{
    public function testControlledPass(): void
    {
        self::assertSame('observed', 'observed');
    }

    public function testControlledFailure(): void
    {
        self::assertSame('expected controlled value', 'actual controlled value');
    }

    public function testControlledError(): void
    {
        throw new \RuntimeException('Deliberate native JUnit error fixture.');
    }

    public function testControlledSkip(): void
    {
        self::markTestSkipped('Deliberate native JUnit skip fixture.');
    }
}
