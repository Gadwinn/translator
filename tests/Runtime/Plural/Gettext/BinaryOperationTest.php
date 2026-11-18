<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime\Plural\Gettext;

use G\Translator\Exception\Runtime\PluralResolutionException;
use G\Translator\Runtime\Plural\Gettext\BinaryOperation;
use G\Translator\Runtime\Plural\Gettext\BinaryOperator;
use G\Translator\Runtime\Plural\Gettext\CountVariable;
use G\Translator\Runtime\Plural\Gettext\IntegerLiteral;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BinaryOperation::class)]
#[CoversClass(CountVariable::class)]
#[CoversClass(IntegerLiteral::class)]
final class BinaryOperationTest extends TestCase
{
    // --- Arithmetic ---

    public function testAddition(): void
    {
        // n + 3 with n=2 → 5
        $node = new BinaryOperation(
            BinaryOperator::Add,
            new CountVariable(),
            new IntegerLiteral(3),
        );

        self::assertSame(5, $node->evaluate(2));
    }

    public function testSubtraction(): void
    {
        // n - 1 with n=4 → 3
        $node = new BinaryOperation(
            BinaryOperator::Subtract,
            new CountVariable(),
            new IntegerLiteral(1),
        );

        self::assertSame(3, $node->evaluate(4));
    }

    public function testMultiplication(): void
    {
        // n * 2 with n=5 → 10
        $node = new BinaryOperation(
            BinaryOperator::Multiply,
            new CountVariable(),
            new IntegerLiteral(2),
        );

        self::assertSame(10, $node->evaluate(5));
    }

    public function testDivision(): void
    {
        // n / 3 with n=9 → 3
        $node = new BinaryOperation(
            BinaryOperator::Divide,
            new CountVariable(),
            new IntegerLiteral(3),
        );

        self::assertSame(3, $node->evaluate(9));
    }

    public function testDivisionByZeroThrows(): void
    {
        $node = new BinaryOperation(
            BinaryOperator::Divide,
            new CountVariable(),
            new IntegerLiteral(0),
        );

        $this->expectException(PluralResolutionException::class);
        $node->evaluate(5);
    }

    public function testModulo(): void
    {
        // n % 3 with n=10 → 1
        $node = new BinaryOperation(
            BinaryOperator::Modulo,
            new CountVariable(),
            new IntegerLiteral(3),
        );

        self::assertSame(1, $node->evaluate(10));
    }

    public function testModuloByZeroThrows(): void
    {
        $node = new BinaryOperation(
            BinaryOperator::Modulo,
            new CountVariable(),
            new IntegerLiteral(0),
        );

        $this->expectException(PluralResolutionException::class);
        $node->evaluate(5);
    }

    // --- Comparison ---

    public function testEqual(): void
    {
        $eq = new BinaryOperation(BinaryOperator::Equal, new CountVariable(), new IntegerLiteral(1));

        self::assertSame(1, $eq->evaluate(1));
        self::assertSame(0, $eq->evaluate(2));
    }

    public function testNotEqual(): void
    {
        $neq = new BinaryOperation(BinaryOperator::NotEqual, new CountVariable(), new IntegerLiteral(1));

        self::assertSame(0, $neq->evaluate(1));
        self::assertSame(1, $neq->evaluate(2));
    }

    public function testLess(): void
    {
        $lt = new BinaryOperation(BinaryOperator::Less, new CountVariable(), new IntegerLiteral(5));

        self::assertSame(1, $lt->evaluate(3));
        self::assertSame(0, $lt->evaluate(5));
        self::assertSame(0, $lt->evaluate(7));
    }

    public function testGreater(): void
    {
        $gt = new BinaryOperation(BinaryOperator::Greater, new CountVariable(), new IntegerLiteral(5));

        self::assertSame(0, $gt->evaluate(3));
        self::assertSame(0, $gt->evaluate(5));
        self::assertSame(1, $gt->evaluate(7));
    }

    public function testLessOrEqual(): void
    {
        $lte = new BinaryOperation(BinaryOperator::LessOrEqual, new CountVariable(), new IntegerLiteral(5));

        self::assertSame(1, $lte->evaluate(3));
        self::assertSame(1, $lte->evaluate(5));
        self::assertSame(0, $lte->evaluate(6));
    }

    public function testGreaterOrEqual(): void
    {
        $gte = new BinaryOperation(BinaryOperator::GreaterOrEqual, new CountVariable(), new IntegerLiteral(5));

        self::assertSame(0, $gte->evaluate(3));
        self::assertSame(1, $gte->evaluate(5));
        self::assertSame(1, $gte->evaluate(6));
    }

    // --- Logical ---

    public function testLogicalAndShortCircuitsOnFalseLeft(): void
    {
        // 0 && <right never evaluated> → right operand is a divide-by-zero that would throw
        // but thanks to short-circuit it must NOT throw
        $node = new BinaryOperation(
            BinaryOperator::LogicalAnd,
            new IntegerLiteral(0),
            new BinaryOperation(BinaryOperator::Divide, new IntegerLiteral(1), new IntegerLiteral(0)),
        );

        self::assertSame(0, $node->evaluate(0));
    }

    public function testLogicalAndReturnsTrueWhenBothOperandsAreNonZero(): void
    {
        $node = new BinaryOperation(
            BinaryOperator::LogicalAnd,
            new IntegerLiteral(1),
            new CountVariable(),
        );

        self::assertSame(1, $node->evaluate(5));
        self::assertSame(0, $node->evaluate(0));
    }

    public function testLogicalOrShortCircuitsOnTrueLeft(): void
    {
        // 1 || <right never evaluated> → right operand is a divide-by-zero that would throw
        // but thanks to short-circuit it must NOT throw
        $node = new BinaryOperation(
            BinaryOperator::LogicalOr,
            new IntegerLiteral(1),
            new BinaryOperation(BinaryOperator::Divide, new IntegerLiteral(1), new IntegerLiteral(0)),
        );

        self::assertSame(1, $node->evaluate(0));
    }

    public function testLogicalOrReturnsFalseWhenBothOperandsAreZero(): void
    {
        $node = new BinaryOperation(
            BinaryOperator::LogicalOr,
            new IntegerLiteral(0),
            new CountVariable(),
        );

        self::assertSame(0, $node->evaluate(0));
        self::assertSame(1, $node->evaluate(3));
    }

    // --- CountVariable and IntegerLiteral coverage ---

    public function testCountVariableReturnsN(): void
    {
        $node = new CountVariable();

        self::assertSame(0, $node->evaluate(0));
        self::assertSame(42, $node->evaluate(42));
    }

    public function testIntegerLiteralIgnoresN(): void
    {
        $node = new IntegerLiteral(7);

        self::assertSame(7, $node->evaluate(0));
        self::assertSame(7, $node->evaluate(99));
    }
}
