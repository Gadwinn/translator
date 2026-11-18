<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural\Gettext;

/**
 * AST node representing a non-negative integer literal.
 *
 * The Gettext grammar only emits non-negative integers; negative
 * values are produced by a `UnaryOperation` wrapping a literal.
 */
final readonly class IntegerLiteral extends ExpressionNode
{
    /**
     * @param int $value The literal integer.
     */
    public function __construct(public int $value)
    {
    }

    public function evaluate(int $n): int
    {
        return $this->value;
    }
}
