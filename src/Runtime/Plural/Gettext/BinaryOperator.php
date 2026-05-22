<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural\Gettext;

/**
 * Enum of binary operators recognized by the Gettext expression
 * grammar.
 *
 * Arithmetic operators produce integers; comparison and logical
 * operators produce `0` (false) or `1` (true), per the C/Gettext
 * convention.
 *
 * The string-backed value is the operator's source representation.
 */
enum BinaryOperator: string
{
    case Add            = '+';
    case Subtract       = '-';
    case Multiply       = '*';
    case Divide         = '/';
    case Modulo         = '%';
    case Equal          = '==';
    case NotEqual       = '!=';
    case Less           = '<';
    case Greater        = '>';
    case LessOrEqual    = '<=';
    case GreaterOrEqual = '>=';
    case LogicalAnd     = '&&';
    case LogicalOr      = '||';
}
