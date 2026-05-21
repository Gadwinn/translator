<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime\Plural;

use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Runtime\PluralResolutionException;
use G\Translator\Runtime\Plural\CldrPluralResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CldrPluralResolver::class)]
final class CldrPluralResolverTest extends TestCase
{
    private CldrPluralResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CldrPluralResolver();
    }

    /**
     * Canonical CLDR test data: for each supported locale we exercise
     * at least 3 representative counts producing distinct categories.
     *
     * @return iterable<string, array{non-empty-string, int, PluralCategory}>
     */
    public static function cldrCases(): iterable
    {
        // OneIsOne family
        yield 'en-1-one'       => ['en',    1, PluralCategory::ONE];
        yield 'en-0-other'     => ['en',    0, PluralCategory::OTHER];
        yield 'en-5-other'     => ['en',    5, PluralCategory::OTHER];
        yield 'de-1-one'       => ['de-DE', 1, PluralCategory::ONE];
        yield 'de-2-other'     => ['de-DE', 2, PluralCategory::OTHER];
        yield 'nl-1-one'       => ['nl',    1, PluralCategory::ONE];
        yield 'nl-7-other'     => ['nl',    7, PluralCategory::OTHER];

        // ZeroOrOneIsOne family
        yield 'fr-0-one'       => ['fr-FR', 0, PluralCategory::ONE];
        yield 'fr-1-one'       => ['fr-FR', 1, PluralCategory::ONE];
        yield 'fr-2-other'     => ['fr-FR', 2, PluralCategory::OTHER];
        yield 'pt-BR-0-one'    => ['pt-BR', 0, PluralCategory::ONE];
        yield 'pt-BR-3-other'  => ['pt-BR', 3, PluralCategory::OTHER];
        yield 'pt-PT-0-other'  => ['pt-PT', 0, PluralCategory::OTHER]; // parent fallback to pt (OneIsOne)
        yield 'pt-PT-1-one'    => ['pt-PT', 1, PluralCategory::ONE];
        yield 'hi-0-one'       => ['hi',    0, PluralCategory::ONE];

        // NoPlural family
        yield 'ja-1-other'     => ['ja',    1, PluralCategory::OTHER];
        yield 'ja-5-other'     => ['ja',    5, PluralCategory::OTHER];
        yield 'zh-CN-1-other'  => ['zh-CN', 1, PluralCategory::OTHER];

        // SlavicEast family (Russian)
        yield 'ru-1-one'       => ['ru',    1, PluralCategory::ONE];
        yield 'ru-21-one'      => ['ru',   21, PluralCategory::ONE];
        yield 'ru-11-many'     => ['ru',   11, PluralCategory::MANY];
        yield 'ru-2-few'       => ['ru',    2, PluralCategory::FEW];
        yield 'ru-22-few'      => ['ru',   22, PluralCategory::FEW];
        yield 'ru-5-many'      => ['ru',    5, PluralCategory::MANY];
        yield 'uk-1-one'       => ['uk',    1, PluralCategory::ONE];
        yield 'uk-2-few'       => ['uk',    2, PluralCategory::FEW];
        yield 'uk-10-many'     => ['uk',   10, PluralCategory::MANY];

        // Polish family
        yield 'pl-1-one'       => ['pl',    1, PluralCategory::ONE];
        yield 'pl-2-few'       => ['pl',    2, PluralCategory::FEW];
        yield 'pl-5-many'      => ['pl',    5, PluralCategory::MANY];
        yield 'pl-22-few'      => ['pl',   22, PluralCategory::FEW];
        yield 'pl-12-many'     => ['pl',   12, PluralCategory::MANY];

        // SlavicWest family (Czech)
        yield 'cs-1-one'       => ['cs',    1, PluralCategory::ONE];
        yield 'cs-3-few'       => ['cs',    3, PluralCategory::FEW];
        yield 'cs-5-other'     => ['cs',    5, PluralCategory::OTHER];
        yield 'sk-1-one'       => ['sk',    1, PluralCategory::ONE];
        yield 'sk-2-few'       => ['sk',    2, PluralCategory::FEW];

        // Romanian
        yield 'ro-1-one'       => ['ro',    1, PluralCategory::ONE];
        yield 'ro-0-few'       => ['ro',    0, PluralCategory::FEW];
        yield 'ro-19-few'      => ['ro',   19, PluralCategory::FEW];
        yield 'ro-20-other'    => ['ro',   20, PluralCategory::OTHER];

        // Arabic (6 categories)
        yield 'ar-0-zero'      => ['ar',    0, PluralCategory::ZERO];
        yield 'ar-1-one'       => ['ar',    1, PluralCategory::ONE];
        yield 'ar-2-two'       => ['ar',    2, PluralCategory::TWO];
        yield 'ar-3-few'       => ['ar',    3, PluralCategory::FEW];
        yield 'ar-11-many'     => ['ar',   11, PluralCategory::MANY];
        yield 'ar-100-other'   => ['ar',  100, PluralCategory::OTHER];

        // Hebrew
        yield 'he-1-one'       => ['he',    1, PluralCategory::ONE];
        yield 'he-2-two'       => ['he',    2, PluralCategory::TWO];
        yield 'he-20-many'     => ['he',   20, PluralCategory::MANY];
        yield 'he-3-other'     => ['he',    3, PluralCategory::OTHER];

        // Slovenian
        yield 'sl-1-one'       => ['sl',    1, PluralCategory::ONE];
        yield 'sl-2-two'       => ['sl',    2, PluralCategory::TWO];
        yield 'sl-3-few'       => ['sl',    3, PluralCategory::FEW];
        yield 'sl-5-other'     => ['sl',    5, PluralCategory::OTHER];

        // Latvian
        yield 'lv-0-zero'      => ['lv',    0, PluralCategory::ZERO];
        yield 'lv-11-zero'     => ['lv',   11, PluralCategory::ZERO];
        yield 'lv-1-one'       => ['lv',    1, PluralCategory::ONE];
        yield 'lv-21-one'      => ['lv',   21, PluralCategory::ONE];
        yield 'lv-2-other'     => ['lv',    2, PluralCategory::OTHER];

        // Lithuanian
        yield 'lt-1-one'       => ['lt',    1, PluralCategory::ONE];
        yield 'lt-11-other'    => ['lt',   11, PluralCategory::OTHER];
        yield 'lt-2-few'       => ['lt',    2, PluralCategory::FEW];

        // Irish (5 categories)
        yield 'ga-1-one'       => ['ga',    1, PluralCategory::ONE];
        yield 'ga-2-two'       => ['ga',    2, PluralCategory::TWO];
        yield 'ga-3-few'       => ['ga',    3, PluralCategory::FEW];
        yield 'ga-8-many'      => ['ga',    8, PluralCategory::MANY];
        yield 'ga-15-other'    => ['ga',   15, PluralCategory::OTHER];

        // Welsh (6 categories)
        yield 'cy-0-zero'      => ['cy',    0, PluralCategory::ZERO];
        yield 'cy-1-one'       => ['cy',    1, PluralCategory::ONE];
        yield 'cy-2-two'       => ['cy',    2, PluralCategory::TWO];
        yield 'cy-3-few'       => ['cy',    3, PluralCategory::FEW];
        yield 'cy-6-many'      => ['cy',    6, PluralCategory::MANY];
        yield 'cy-4-other'     => ['cy',    4, PluralCategory::OTHER];

        // Maltese
        yield 'mt-1-one'       => ['mt',    1, PluralCategory::ONE];
        yield 'mt-0-few'       => ['mt',    0, PluralCategory::FEW];
        yield 'mt-15-many'     => ['mt',   15, PluralCategory::MANY];
        yield 'mt-25-other'    => ['mt',   25, PluralCategory::OTHER];
    }

    #[DataProvider('cldrCases')]
    public function testCldr(string $locale, int $count, PluralCategory $expected): void
    {
        self::assertSame(
            $expected,
            $this->resolver->resolve($count, $locale),
            sprintf('Expected %s for %s count=%d', $expected->value, $locale, $count),
        );
    }

    public function testNegativeCountUsesAbsoluteValue(): void
    {
        self::assertSame(PluralCategory::ONE, $this->resolver->resolve(-1, 'en'));
    }

    public function testUnknownLocaleThrows(): void
    {
        $this->expectException(PluralResolutionException::class);
        $this->resolver->resolve(1, 'xx-XX');
    }

    public function testLocaleParentLookupFallsBackToLanguage(): void
    {
        // pt-PT not explicitly mapped → falls back to pt → OneIsOne family
        self::assertSame(PluralCategory::ONE, $this->resolver->resolve(1, 'pt-PT'));
    }
}
