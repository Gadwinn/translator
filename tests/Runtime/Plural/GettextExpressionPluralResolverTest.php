<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime\Plural;

use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Runtime\PluralResolutionException;
use G\Translator\Runtime\Plural\GettextExpressionPluralResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GettextExpressionPluralResolver::class)]
final class GettextExpressionPluralResolverTest extends TestCase
{
    public function testEnglishStandardExpression(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('en', 'n != 1', 2);

        self::assertSame(PluralCategory::ONE, $resolver->resolve(1, 'en'));
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(2, 'en'));
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(0, 'en'));
    }

    public function testFrenchStandardExpression(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('fr', 'n > 1', 2);

        self::assertSame(PluralCategory::ONE, $resolver->resolve(0, 'fr'));
        self::assertSame(PluralCategory::ONE, $resolver->resolve(1, 'fr'));
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(5, 'fr'));
    }

    public function testRussianStandardExpression(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register(
            'ru',
            'n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2',
            3,
        );

        self::assertSame(PluralCategory::ONE,  $resolver->resolve(1,  'ru'));
        self::assertSame(PluralCategory::ONE,  $resolver->resolve(21, 'ru'));
        self::assertSame(PluralCategory::FEW,  $resolver->resolve(2,  'ru'));
        self::assertSame(PluralCategory::FEW,  $resolver->resolve(22, 'ru'));
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(5,  'ru'));
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(11, 'ru'));
    }

    public function testPolishStandardExpression(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register(
            'pl',
            'n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2',
            3,
        );

        self::assertSame(PluralCategory::ONE,   $resolver->resolve(1,  'pl'));
        self::assertSame(PluralCategory::FEW,   $resolver->resolve(2,  'pl'));
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(5,  'pl'));
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(12, 'pl'));
    }

    public function testArabicSixFormExpression(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register(
            'ar',
            'n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5',
            6,
        );

        self::assertSame(PluralCategory::ZERO,  $resolver->resolve(0,   'ar'));
        self::assertSame(PluralCategory::ONE,   $resolver->resolve(1,   'ar'));
        self::assertSame(PluralCategory::TWO,   $resolver->resolve(2,   'ar'));
        self::assertSame(PluralCategory::FEW,   $resolver->resolve(3,   'ar'));
        self::assertSame(PluralCategory::MANY,  $resolver->resolve(11,  'ar'));
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(100, 'ar'));
    }

    public function testJapaneseSingleForm(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('ja', '0', 1);

        self::assertSame(PluralCategory::OTHER, $resolver->resolve(1, 'ja'));
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(5, 'ja'));
    }

    public function testTrailingSemicolonIsTolerated(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('en', 'n != 1;', 2);
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(0, 'en'));
    }

    public function testParenthesesGrouping(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('en', '(n + 1) * 0', 2);
        // (3+1)*0 = 0 → form 0 → ONE
        self::assertSame(PluralCategory::ONE, $resolver->resolve(3, 'en'));
    }

    public function testUnaryNegation(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('en', '-n + n', 2);
        // -3 + 3 = 0 → form 0 → ONE
        self::assertSame(PluralCategory::ONE, $resolver->resolve(3, 'en'));
    }

    public function testLogicalNot(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('en', '!n', 2);
        // !3 = 0 → form 0 → ONE; !0 = 1 → form 1 → OTHER
        self::assertSame(PluralCategory::ONE,   $resolver->resolve(3, 'en'));
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(0, 'en'));
    }

    public function testReregisteringReplaces(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('en', '0', 1);
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(99, 'en'));

        $resolver->register('en', 'n != 1', 2);
        self::assertSame(PluralCategory::OTHER, $resolver->resolve(99, 'en'));
        self::assertSame(PluralCategory::ONE,   $resolver->resolve(1, 'en'));
    }

    public function testLocaleNormalization(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('en_US', 'n != 1', 2);

        self::assertTrue($resolver->has('en-US'));
        self::assertSame(PluralCategory::ONE, $resolver->resolve(1, 'en-us'));
    }

    public function testUnregisteredLocaleThrows(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $this->expectException(PluralResolutionException::class);
        $resolver->resolve(1, 'en');
    }

    public function testOutOfRangeFormIndexThrows(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        // 2-form locale but expression returns 5 → out of range
        $resolver->register('en', '5', 2);
        $this->expectException(PluralResolutionException::class);
        $resolver->resolve(0, 'en');
    }

    public function testInvalidNpluralsThrows(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $this->expectException(PluralResolutionException::class);
        $resolver->register('en', 'n != 1', 99);
    }

    /**
     * Security-focused tests. The whole reason a hand-rolled parser
     * exists is to refuse anything that could be executed as PHP.
     *
     * @return iterable<string, array{string}>
     */
    public static function maliciousExpressions(): iterable
    {
        yield 'php function call' => ['phpinfo()'];
        yield 'system call'        => ['system("rm -rf /")'];
        yield 'assignment'         => ['n = 1'];
        yield 'php variable'       => ['$n'];
        yield 'string literal'     => ['"hack"'];
        yield 'identifier other than n' => ['x + 1'];
        yield 'backtick'           => ['`whoami`'];
        yield 'semicolon mid expr' => ['n != 1; echo "pwn"'];
        yield 'unbalanced parens'  => ['((n + 1)'];
        yield 'missing colon in ternary' => ['n == 1 ? 0'];
        yield 'empty expression'   => [''];
        yield 'array syntax'       => ['[1, 2, 3]'];
    }

    #[DataProvider('maliciousExpressions')]
    public function testMaliciousExpressionsAreRejected(string $expression): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $this->expectException(PluralResolutionException::class);
        $resolver->register('en', $expression, 2);
    }

    public function testDivisionByZeroSurfacedAsPluralResolution(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('en', 'n / 0', 2);
        $this->expectException(PluralResolutionException::class);
        $resolver->resolve(5, 'en');
    }

    public function testModuloByZeroSurfacedAsPluralResolution(): void
    {
        $resolver = new GettextExpressionPluralResolver();
        $resolver->register('en', 'n % 0', 2);
        $this->expectException(PluralResolutionException::class);
        $resolver->resolve(5, 'en');
    }
}
