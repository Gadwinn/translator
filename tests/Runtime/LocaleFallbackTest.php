<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime;

use G\Translator\Exception\Runtime\InvalidLocaleException;
use G\Translator\Runtime\LocaleFallback;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocaleFallback::class)]
final class LocaleFallbackTest extends TestCase
{
    public function testDefaultPrimaryIsEnglish(): void
    {
        $fallback = new LocaleFallback();
        self::assertSame('en', $fallback->primary());
        self::assertSame(['en'], $fallback->chain());
    }

    public function testSetPrimaryNormalizes(): void
    {
        $fallback = new LocaleFallback();
        $fallback->setPrimary('pl_pl');
        self::assertSame('pl-PL', $fallback->primary());
    }

    public function testSetPrimaryRejectsMalformedLocale(): void
    {
        $fallback = new LocaleFallback();
        $this->expectException(InvalidLocaleException::class);
        $fallback->setPrimary(' ');
    }

    public function testSetChainNormalizesAndDedupes(): void
    {
        $fallback = new LocaleFallback('pl-PL');
        $fallback->setChain(['en_GB', 'en-gb', 'pl_PL', 'fr']);

        self::assertSame(['en-GB', 'fr'], $fallback->explicitChain());
    }

    public function testChainWithoutAutoParentIsExplicitOnly(): void
    {
        $fallback = new LocaleFallback('pl-PL');
        $fallback->setChain(['en-GB', 'en']);

        self::assertSame(['pl-PL', 'en-GB', 'en'], $fallback->chain());
    }

    public function testChainWithAutoParentExpandsBothPrimaryAndChainEntries(): void
    {
        $fallback = new LocaleFallback('pl-PL');
        $fallback->setChain(['en-GB']);
        $fallback->setAutoParent(true);

        self::assertSame(['pl-PL', 'pl', 'en-GB', 'en'], $fallback->chain());
    }

    public function testChainWithAutoParentExpandsScriptedLocales(): void
    {
        $fallback = new LocaleFallback('zh-Hant-TW');
        $fallback->setAutoParent(true);

        self::assertSame(['zh-Hant-TW', 'zh-Hant', 'zh'], $fallback->chain());
    }

    public function testChainHasNoDuplicatesAcrossExpansion(): void
    {
        $fallback = new LocaleFallback('en-GB');
        $fallback->setChain(['en-US', 'en']);
        $fallback->setAutoParent(true);

        // Primary expands to en-GB, en. en-US expands to en-US, en (deduped).
        self::assertSame(['en-GB', 'en', 'en-US'], $fallback->chain());
    }

    public function testIsAutoParentEnabledReflectsState(): void
    {
        $fallback = new LocaleFallback();
        self::assertFalse($fallback->isAutoParentEnabled());

        $fallback->setAutoParent(true);
        self::assertTrue($fallback->isAutoParentEnabled());
    }
}
