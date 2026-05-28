<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime\Plural\Gettext;

use G\Translator\Exception\Runtime\PluralResolutionException;
use G\Translator\Runtime\Plural\Gettext\BinaryOperation;
use G\Translator\Runtime\Plural\Gettext\CountVariable;
use G\Translator\Runtime\Plural\Gettext\ExpressionParser;
use G\Translator\Runtime\Plural\Gettext\IntegerLiteral;
use G\Translator\Runtime\Plural\Gettext\TernaryOperation;
use G\Translator\Runtime\Plural\Gettext\UnaryOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionParser::class)]
#[CoversClass(TernaryOperation::class)]
#[CoversClass(UnaryOperation::class)]
final class ExpressionParserTest extends TestCase
{
    // --- Basic literals and variable ---

    public function testParsesIntegerLiteral(): void
    {
        $node = ExpressionParser::parse('0');

        self::assertSame(0, $node->evaluate(99));
    }

    public function testParsesCountVariable(): void
    {
        $node = ExpressionParser::parse('n');

        self::assertSame(5, $node->evaluate(5));
        self::assertSame(0, $node->evaluate(0));
    }

    // --- Arithmetic ---

    public function testParsesAddition(): void
    {
        $node = ExpressionParser::parse('n + 1');

        self::assertSame(6, $node->evaluate(5));
    }

    public function testParsesSubtraction(): void
    {
        $node = ExpressionParser::parse('n - 2');

        self::assertSame(3, $node->evaluate(5));
    }

    public function testParsesMultiplication(): void
    {
        $node = ExpressionParser::parse('n * 3');

        self::assertSame(15, $node->evaluate(5));
    }

    public function testParsesDivision(): void
    {
        $node = ExpressionParser::parse('n / 2');

        self::assertSame(4, $node->evaluate(8));
    }

    public function testParsesModulo(): void
    {
        $node = ExpressionParser::parse('n % 10');

        self::assertSame(3, $node->evaluate(13));
    }

    // --- Comparison ---

    public function testParsesEqual(): void
    {
        $node = ExpressionParser::parse('n == 1');

        self::assertSame(1, $node->evaluate(1));
        self::assertSame(0, $node->evaluate(2));
    }

    public function testParsesNotEqual(): void
    {
        $node = ExpressionParser::parse('n != 1');

        self::assertSame(0, $node->evaluate(1));
        self::assertSame(1, $node->evaluate(2));
    }

    public function testParsesLess(): void
    {
        $node = ExpressionParser::parse('n < 5');

        self::assertSame(1, $node->evaluate(3));
        self::assertSame(0, $node->evaluate(5));
    }

    public function testParsesGreater(): void
    {
        $node = ExpressionParser::parse('n > 1');

        self::assertSame(1, $node->evaluate(2));
        self::assertSame(0, $node->evaluate(1));
    }

    public function testParsesLessOrEqual(): void
    {
        $node = ExpressionParser::parse('n <= 5');

        self::assertSame(1, $node->evaluate(5));
        self::assertSame(0, $node->evaluate(6));
    }

    public function testParsesGreaterOrEqual(): void
    {
        $node = ExpressionParser::parse('n >= 5');

        self::assertSame(1, $node->evaluate(5));
        self::assertSame(0, $node->evaluate(4));
    }

    // --- Logical ---

    public function testParsesLogicalAnd(): void
    {
        $node = ExpressionParser::parse('n > 1 && n < 10');

        self::assertSame(1, $node->evaluate(5));
        self::assertSame(0, $node->evaluate(1));
        self::assertSame(0, $node->evaluate(10));
    }

    public function testParsesLogicalOr(): void
    {
        $node = ExpressionParser::parse('n == 1 || n == 2');

        self::assertSame(1, $node->evaluate(1));
        self::assertSame(1, $node->evaluate(2));
        self::assertSame(0, $node->evaluate(3));
    }

    // --- Unary ---

    public function testParsesUnaryNot(): void
    {
        $node = ExpressionParser::parse('!n');

        self::assertSame(1, $node->evaluate(0));
        self::assertSame(0, $node->evaluate(1));
        self::assertSame(0, $node->evaluate(5));
    }

    public function testParsesUnaryNegate(): void
    {
        $node = ExpressionParser::parse('-n');

        self::assertSame(0, $node->evaluate(0));
        self::assertSame(-3, $node->evaluate(3));
    }

    // --- Ternary ---

    public function testParsesTernary(): void
    {
        // English: n != 1 → 1 form index else 0
        $node = ExpressionParser::parse('n != 1 ? 1 : 0');

        self::assertSame(0, $node->evaluate(1));
        self::assertSame(1, $node->evaluate(5));
    }

    public function testParsesNestedTernary(): void
    {
        // Russian-like: n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 ? 1 : 2
        $expr = 'n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2';
        $node = ExpressionParser::parse($expr);

        self::assertSame(0, $node->evaluate(1));
        self::assertSame(0, $node->evaluate(21));
        self::assertSame(1, $node->evaluate(2));
        self::assertSame(2, $node->evaluate(5));
        self::assertSame(2, $node->evaluate(11));
    }

    // --- Parentheses ---

    public function testParsesParenthesizedExpression(): void
    {
        $node = ExpressionParser::parse('(n + 2) * 3');

        self::assertSame(15, $node->evaluate(3));
    }

    // --- Trailing semicolon tolerance ---

    public function testToleratesTrailingSemicolon(): void
    {
        $node = ExpressionParser::parse('n != 1;');

        self::assertSame(1, $node->evaluate(5));
    }

    public function testToleratesTrailingSemicolonWithWhitespace(): void
    {
        $node = ExpressionParser::parse('n != 1 ;  ');

        self::assertSame(0, $node->evaluate(1));
    }

    // --- Error cases ---

    public function testThrowsOnEmptyExpression(): void
    {
        $this->expectException(PluralResolutionException::class);
        ExpressionParser::parse('');
    }

    public function testThrowsOnUnknownIdentifier(): void
    {
        $this->expectException(PluralResolutionException::class);
        ExpressionParser::parse('x');
    }

    public function testThrowsOnIdentifierStartingWithN(): void
    {
        $this->expectException(PluralResolutionException::class);
        ExpressionParser::parse('nope');
    }

    public function testThrowsOnTrailingGarbage(): void
    {
        $this->expectException(PluralResolutionException::class);
        ExpressionParser::parse('n + 1 garbage');
    }

    public function testThrowsOnMissingColonInTernary(): void
    {
        $this->expectException(PluralResolutionException::class);
        ExpressionParser::parse('n ? 1');
    }

    public function testThrowsOnMissingClosingParenthesis(): void
    {
        $this->expectException(PluralResolutionException::class);
        ExpressionParser::parse('(n + 1');
    }

    public function testThrowsOnUnexpectedCharacter(): void
    {
        $this->expectException(PluralResolutionException::class);
        ExpressionParser::parse('@n');
    }

    // --- Real-world expressions ---

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function realWorldExpressionProvider(): array
    {
        return [
            'English n!=1 / singular'   => ['n != 1', 1, 0],
            'English n!=1 / plural'     => ['n != 1', 5, 1],
            'French n>1 / singular 0'   => ['n > 1', 0, 0],
            'French n>1 / plural'       => ['n > 1', 5, 1],
            'Japanese always 0'         => ['0', 99, 0],
        ];
    }

    #[DataProvider('realWorldExpressionProvider')]
    public function testRealWorldExpression(string $expr, int $n, int $expected): void
    {
        $node = ExpressionParser::parse($expr);

        self::assertSame($expected, $node->evaluate($n));
    }
}
