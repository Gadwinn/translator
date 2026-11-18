<?php

declare(strict_types=1);

namespace G\Translator\Tests\Bridge\Ai\Enum;

use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationQuality::class)]
final class TranslationQualityTest extends TestCase
{
    public function testDefaultReturnsStandard(): void
    {
        self::assertSame(TranslationQuality::STANDARD, TranslationQuality::default());
    }

    public function testCasesHaveExpectedBackingValues(): void
    {
        self::assertSame('fast', TranslationQuality::FAST->value);
        self::assertSame('standard', TranslationQuality::STANDARD->value);
        self::assertSame('high', TranslationQuality::HIGH->value);
    }
}
