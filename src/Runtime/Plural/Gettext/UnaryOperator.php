<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural\Gettext;

/**
 * Enum of unary operators recognized by the Gettext expression
 * grammar.
 *
 * - `Negate` flips the sign of an integer operand.
 * - `Not`    returns `1` if the operand is `0`, else `0`.
 */
enum UnaryOperator: string
{
    case Negate = '-';
    case Not    = '!';
}
