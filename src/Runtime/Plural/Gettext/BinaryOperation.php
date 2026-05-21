<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural\Gettext;

use G\Translator\Exception\Runtime\PluralResolutionException;

/**
 * AST node representing a binary operation between two operands.
 *
 * Both arithmetic (`+`, `-`, `*`, `/`, `%`), comparison
 * (`<`, `>`, `<=`, `>=`, `==`, `!=`) and logical (`&&`, `||`)
 * operators are supported. Logical operators short-circuit per
 * the C convention: `&&` returns `0` without evaluating the right
 * operand if the left is falsy; `||` returns `1` without evaluating
 * the right operand if the left is truthy.
 *
 * Division and modulo by zero throw `PluralResolutionException` —
 * the Gettext spec is silent on this case but PHP would otherwise
 * raise a fatal error; we surface it through the package's
 * exception hierarchy instead.
 */
final readonly class BinaryOperation extends ExpressionNode
{
    public function __construct(
        public BinaryOperator $operator,
        public ExpressionNode $left,
        public ExpressionNode $right,
    ) {
    }

    public function evaluate(int $n): int
    {
        if ($this->operator === BinaryOperator::LogicalAnd) {
            return $this->left->evaluate($n) !== 0 && $this->right->evaluate($n) !== 0 ? 1 : 0;
        }

        if ($this->operator === BinaryOperator::LogicalOr) {
            return $this->left->evaluate($n) !== 0 || $this->right->evaluate($n) !== 0 ? 1 : 0;
        }

        $leftValue = $this->left->evaluate($n);
        $rightValue = $this->right->evaluate($n);

        return match ($this->operator) {
            BinaryOperator::Add            => $leftValue + $rightValue,
            BinaryOperator::Subtract       => $leftValue - $rightValue,
            BinaryOperator::Multiply       => $leftValue * $rightValue,
            BinaryOperator::Divide         => $this->divide($leftValue, $rightValue),
            BinaryOperator::Modulo         => $this->modulo($leftValue, $rightValue),
            BinaryOperator::Equal          => $leftValue === $rightValue ? 1 : 0,
            BinaryOperator::NotEqual       => $leftValue !== $rightValue ? 1 : 0,
            BinaryOperator::Less           => $leftValue < $rightValue ? 1 : 0,
            BinaryOperator::Greater        => $leftValue > $rightValue ? 1 : 0,
            BinaryOperator::LessOrEqual    => $leftValue <= $rightValue ? 1 : 0,
            BinaryOperator::GreaterOrEqual => $leftValue >= $rightValue ? 1 : 0,
        };
    }

    private function divide(int $left, int $right): int
    {
        if ($right === 0) {
            throw new PluralResolutionException(
                'Gettext plural expression division by zero.',
            );
        }

        return intdiv($left, $right);
    }

    private function modulo(int $left, int $right): int
    {
        if ($right === 0) {
            throw new PluralResolutionException(
                'Gettext plural expression modulo by zero.',
            );
        }

        return $left % $right;
    }
}
