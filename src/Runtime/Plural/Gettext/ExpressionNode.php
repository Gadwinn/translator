<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural\Gettext;

/**
 * Abstract base of the Gettext plural-form AST.
 *
 * Every concrete node carries enough information to evaluate
 * itself against an integer `n` (the count) and return an
 * integer. Booleans inside the expression follow the C/Gettext
 * convention: `0` is false, any non-zero value is true; comparison
 * and logical operators return either `0` or `1`.
 *
 * Nodes MUST be immutable: they are constructed once by the parser
 * and may be cached for the lifetime of the process.
 */
abstract readonly class ExpressionNode
{
    /**
     * Evaluates the expression for the given count.
     *
     * @param int $n The plural count.
     *
     * @return int The expression's integer result.
     */
    abstract public function evaluate(int $n): int;
}
