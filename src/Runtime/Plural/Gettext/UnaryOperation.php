<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural\Gettext;

/**
 * AST node representing a unary operation (`!x` or `-x`).
 *
 * `Negate` returns the arithmetic negation of its operand.
 * `Not` returns `1` if the operand is `0`, else `0`, mirroring the
 * C/Gettext truthiness convention.
 */
final readonly class UnaryOperation extends ExpressionNode
{
    public function __construct(
        public UnaryOperator $operator,
        public ExpressionNode $operand,
    ) {
    }

    public function evaluate(int $n): int
    {
        $value = $this->operand->evaluate($n);

        return match ($this->operator) {
            UnaryOperator::Negate => -$value,
            UnaryOperator::Not    => $value === 0 ? 1 : 0,
        };
    }
}
