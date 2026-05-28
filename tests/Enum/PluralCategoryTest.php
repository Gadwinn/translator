<?php

declare(strict_types=1);

namespace G\Translator\Tests\Enum;

use G\Translator\Enum\PluralCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PluralCategory::class)]
final class PluralCategoryTest extends TestCase
{
    public function testCasesHaveExpectedBackingValues(): void
    {
        self::assertSame('zero', PluralCategory::ZERO->value);
        self::assertSame('one', PluralCategory::ONE->value);
        self::assertSame('two', PluralCategory::TWO->value);
        self::assertSame('few', PluralCategory::FEW->value);
        self::assertSame('many', PluralCategory::MANY->value);
        self::assertSame('other', PluralCategory::OTHER->value);
    }

    public function testFallbackReturnsOther(): void
    {
        self::assertSame(PluralCategory::OTHER, PluralCategory::fallback());
    }

    public function testOrderedListReturnsAllCategoriesInCldrOrder(): void
    {
        $expected = [
            PluralCategory::ZERO,
            PluralCategory::ONE,
            PluralCategory::TWO,
            PluralCategory::FEW,
            PluralCategory::MANY,
            PluralCategory::OTHER,
        ];

        self::assertSame($expected, PluralCategory::orderedList());
    }

    public function testOrderedListContainsAllCases(): void
    {
        self::assertCount(count(PluralCategory::cases()), PluralCategory::orderedList());
    }
}
