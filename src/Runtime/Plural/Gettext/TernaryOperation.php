<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural\Gettext;

/**
 * AST node representing the C ternary `condition ? then : else`.
 *
 * The condition is evaluated against the Gettext truthiness
 * convention (non-zero is true). Only the selected branch is
 * evaluated; the other is short-circuited.
 */
final readonly class TernaryOperation extends ExpressionNode
{
    public function __construct(
        public ExpressionNode $condition,
        public ExpressionNode $thenBranch,
        public ExpressionNode $elseBranch,
    ) {
    }

    public function evaluate(int $n): int
    {
        if ($this->condition->evaluate($n) !== 0) {
            return $this->thenBranch->evaluate($n);
        }

        return $this->elseBranch->evaluate($n);
    }
}
