<?php

declare(strict_types=1);

namespace G\Translator\Tests\Enum;

use G\Translator\Enum\MergeStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MergeStrategy::class)]
final class MergeStrategyTest extends TestCase
{
    public function testDefaultReturnsThrowOnConflict(): void
    {
        self::assertSame(MergeStrategy::THROW_ON_CONFLICT, MergeStrategy::default());
    }

    public function testCasesHaveExpectedBackingValues(): void
    {
        self::assertSame('throw_on_conflict', MergeStrategy::THROW_ON_CONFLICT->value);
        self::assertSame('replace', MergeStrategy::REPLACE->value);
        self::assertSame('keep_existing', MergeStrategy::KEEP_EXISTING->value);
    }

    public function testReplaceMutatesOnCollision(): void
    {
        self::assertTrue(MergeStrategy::REPLACE->mutatesOnCollision());
    }

    public function testKeepExistingDoesNotMutateOnCollision(): void
    {
        self::assertFalse(MergeStrategy::KEEP_EXISTING->mutatesOnCollision());
    }

    public function testThrowOnConflictDoesNotMutateOnCollision(): void
    {
        self::assertFalse(MergeStrategy::THROW_ON_CONFLICT->mutatesOnCollision());
    }

    public function testThrowOnConflictIsStrict(): void
    {
        self::assertTrue(MergeStrategy::THROW_ON_CONFLICT->isStrict());
    }

    public function testReplaceIsNotStrict(): void
    {
        self::assertFalse(MergeStrategy::REPLACE->isStrict());
    }

    public function testKeepExistingIsNotStrict(): void
    {
        self::assertFalse(MergeStrategy::KEEP_EXISTING->isStrict());
    }
}
