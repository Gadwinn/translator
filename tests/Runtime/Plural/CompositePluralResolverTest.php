<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime\Plural;

use G\Translator\Enum\PluralCategory;
use G\Translator\Runtime\Plural\CldrPluralResolver;
use G\Translator\Runtime\Plural\CompositePluralResolver;
use G\Translator\Runtime\Plural\GettextExpressionPluralResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositePluralResolver::class)]
final class CompositePluralResolverTest extends TestCase
{
    public function testFallsBackToCldrWhenNoGettextExpression(): void
    {
        $gettext = new GettextExpressionPluralResolver();
        $cldr = new CldrPluralResolver();
        $composite = new CompositePluralResolver($gettext, $cldr);

        self::assertSame(PluralCategory::ONE, $composite->resolve(1, 'en'));
        self::assertSame(PluralCategory::FEW, $composite->resolve(2, 'pl'));
    }

    public function testPrefersGettextWhenExpressionRegistered(): void
    {
        $gettext = new GettextExpressionPluralResolver();
        $cldr = new CldrPluralResolver();

        // Register a deliberately distinct expression for English so we
        // can observe that the composite reaches it instead of CLDR.
        $gettext->register('en', '0', 1);

        $composite = new CompositePluralResolver($gettext, $cldr);

        // CLDR would return ONE for count=1 in English; the Gettext
        // expression returns form 0 → OTHER under nplurals=1.
        self::assertSame(PluralCategory::OTHER, $composite->resolve(1, 'en'));
    }

    public function testOnlyGettextForRegisteredLocaleOthersFallToCldr(): void
    {
        $gettext = new GettextExpressionPluralResolver();
        $cldr = new CldrPluralResolver();
        $gettext->register('en', '0', 1);

        $composite = new CompositePluralResolver($gettext, $cldr);

        // 'en' uses gettext (OTHER), 'fr' falls through to CLDR (ONE).
        self::assertSame(PluralCategory::OTHER, $composite->resolve(1, 'en'));
        self::assertSame(PluralCategory::ONE,   $composite->resolve(1, 'fr'));
    }
}
