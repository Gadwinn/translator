<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural\Gettext;

/**
 * AST node representing the `n` variable (the plural count).
 *
 * The Gettext grammar exposes a single variable, conventionally
 * named `n`, that holds the count passed by the caller. This node
 * simply returns the count it is evaluated with.
 */
final readonly class CountVariable extends ExpressionNode
{
    public function evaluate(int $n): int
    {
        return $n;
    }
}
