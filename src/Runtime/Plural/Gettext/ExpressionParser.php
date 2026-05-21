<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural\Gettext;

use G\Translator\Exception\Runtime\PluralResolutionException;

/**
 * Hand-rolled tokenizer + recursive-descent parser for Gettext-style
 * plural-form expressions.
 *
 * ### Security
 *
 * This parser is the *only* allowed way to evaluate plural
 * expressions inside this package. PO files come from arbitrary
 * sources — uploaded translation files, third-party packages,
 * downloaded archives — and treating their `Plural-Forms` header
 * as PHP code would be a remote-execution vulnerability waiting
 * to happen. THIS PARSER MUST NEVER fall back to `eval()`,
 * `create_function()`, or any other dynamic code execution.
 *
 * ### Grammar (precedence, low to high)
 *
 * ```
 * expression     := ternary
 * ternary        := logicalOr ('?' ternary ':' ternary)?
 * logicalOr      := logicalAnd ('||' logicalAnd)*
 * logicalAnd     := equality   ('&&' equality)*
 * equality       := comparison (('==' | '!=') comparison)*
 * comparison     := additive   (('<' | '>' | '<=' | '>=') additive)*
 * additive       := multiplicative (('+' | '-') multiplicative)*
 * multiplicative := unary      (('*' | '/' | '%') unary)*
 * unary          := ('!' | '-')? primary
 * primary        := INT | 'n' | '(' expression ')'
 * ```
 *
 * Everything outside this grammar (function calls, identifiers
 * other than `n`, string literals, semicolons mid-expression, …)
 * triggers `PluralResolutionException`.
 *
 * ### Trailing characters
 *
 * Real-world `Plural-Forms` headers occasionally trail a `;` or
 * extra whitespace. The parser tolerates a single trailing `;`
 * followed by whitespace; anything else after the expression is
 * an error.
 */
final class ExpressionParser
{
    /**
     * @var int Length of the equality / comparison operator (`==`, `!=`, `<=`, `>=`).
     */
    private const int TWO_CHAR_OPERATOR_LENGTH = 2;

    private string $input;
    private int $position;
    private int $length;

    private function __construct(string $input)
    {
        $this->input = $input;
        $this->position = 0;
        $this->length = strlen($input);
    }

    /**
     * Parses an expression into an AST.
     *
     * @param string $expression The raw Gettext plural-form
     *        expression, as found after `plural=` in the PO header.
     *
     * @throws PluralResolutionException When the expression is
     *         malformed, contains forbidden tokens, or has
     *         unbalanced parentheses.
     */
    public static function parse(string $expression): ExpressionNode
    {
        $parser = new self(trim($expression));
        $node = $parser->parseExpression();
        $parser->skipWhitespace();

        if ($parser->position < $parser->length && $parser->peek() === ';') {
            $parser->position++;
            $parser->skipWhitespace();
        }

        if ($parser->position < $parser->length) {
            throw new PluralResolutionException(sprintf(
                'Unexpected trailing characters at position %d in Gettext expression: "%s".',
                $parser->position,
                $expression,
            ));
        }

        return $node;
    }

    private function parseExpression(): ExpressionNode
    {
        return $this->parseTernary();
    }

    private function parseTernary(): ExpressionNode
    {
        $condition = $this->parseLogicalOr();
        $this->skipWhitespace();

        if ($this->position >= $this->length || $this->peek() !== '?') {
            return $condition;
        }

        $this->position++;
        $thenBranch = $this->parseTernary();
        $this->skipWhitespace();

        if ($this->position >= $this->length || $this->peek() !== ':') {
            throw new PluralResolutionException(
                'Missing ":" in Gettext ternary expression.',
            );
        }

        $this->position++;
        $elseBranch = $this->parseTernary();

        return new TernaryOperation($condition, $thenBranch, $elseBranch);
    }

    private function parseLogicalOr(): ExpressionNode
    {
        $left = $this->parseLogicalAnd();
        while ($this->consumeTwoCharOperator('||')) {
            $right = $this->parseLogicalAnd();
            $left = new BinaryOperation(BinaryOperator::LogicalOr, $left, $right);
        }

        return $left;
    }

    private function parseLogicalAnd(): ExpressionNode
    {
        $left = $this->parseEquality();
        while ($this->consumeTwoCharOperator('&&')) {
            $right = $this->parseEquality();
            $left = new BinaryOperation(BinaryOperator::LogicalAnd, $left, $right);
        }

        return $left;
    }

    private function parseEquality(): ExpressionNode
    {
        $left = $this->parseComparison();
        while (true) {
            if ($this->consumeTwoCharOperator('==')) {
                $left = new BinaryOperation(BinaryOperator::Equal, $left, $this->parseComparison());
                continue;
            }
            if ($this->consumeTwoCharOperator('!=')) {
                $left = new BinaryOperation(BinaryOperator::NotEqual, $left, $this->parseComparison());
                continue;
            }
            break;
        }

        return $left;
    }

    private function parseComparison(): ExpressionNode
    {
        $left = $this->parseAdditive();
        while (true) {
            if ($this->consumeTwoCharOperator('<=')) {
                $left = new BinaryOperation(BinaryOperator::LessOrEqual, $left, $this->parseAdditive());
                continue;
            }
            if ($this->consumeTwoCharOperator('>=')) {
                $left = new BinaryOperation(BinaryOperator::GreaterOrEqual, $left, $this->parseAdditive());
                continue;
            }
            if ($this->consumeSingleCharOperator('<')) {
                $left = new BinaryOperation(BinaryOperator::Less, $left, $this->parseAdditive());
                continue;
            }
            if ($this->consumeSingleCharOperator('>')) {
                $left = new BinaryOperation(BinaryOperator::Greater, $left, $this->parseAdditive());
                continue;
            }
            break;
        }

        return $left;
    }

    private function parseAdditive(): ExpressionNode
    {
        $left = $this->parseMultiplicative();
        while (true) {
            if ($this->consumeSingleCharOperator('+')) {
                $left = new BinaryOperation(BinaryOperator::Add, $left, $this->parseMultiplicative());
                continue;
            }
            if ($this->consumeSingleCharOperator('-')) {
                $left = new BinaryOperation(BinaryOperator::Subtract, $left, $this->parseMultiplicative());
                continue;
            }
            break;
        }

        return $left;
    }

    private function parseMultiplicative(): ExpressionNode
    {
        $left = $this->parseUnary();
        while (true) {
            if ($this->consumeSingleCharOperator('*')) {
                $left = new BinaryOperation(BinaryOperator::Multiply, $left, $this->parseUnary());
                continue;
            }
            if ($this->consumeSingleCharOperator('/')) {
                $left = new BinaryOperation(BinaryOperator::Divide, $left, $this->parseUnary());
                continue;
            }
            if ($this->consumeSingleCharOperator('%')) {
                $left = new BinaryOperation(BinaryOperator::Modulo, $left, $this->parseUnary());
                continue;
            }
            break;
        }

        return $left;
    }

    private function parseUnary(): ExpressionNode
    {
        $this->skipWhitespace();
        if ($this->position < $this->length) {
            $char = $this->peek();
            if ($char === '!') {
                $this->position++;
                return new UnaryOperation(UnaryOperator::Not, $this->parseUnary());
            }
            if ($char === '-') {
                $this->position++;
                return new UnaryOperation(UnaryOperator::Negate, $this->parseUnary());
            }
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): ExpressionNode
    {
        $this->skipWhitespace();
        if ($this->position >= $this->length) {
            throw new PluralResolutionException(
                'Unexpected end of Gettext expression while parsing a primary term.',
            );
        }

        $char = $this->peek();

        if ($char === '(') {
            $this->position++;
            $inner = $this->parseExpression();
            $this->skipWhitespace();
            if ($this->position >= $this->length || $this->peek() !== ')') {
                throw new PluralResolutionException(
                    'Missing closing parenthesis in Gettext expression.',
                );
            }
            $this->position++;
            return $inner;
        }

        if ($char === 'n') {
            $this->position++;
            if ($this->position < $this->length && $this->isIdentifierChar($this->peek())) {
                throw new PluralResolutionException(sprintf(
                    'Unsupported identifier starting with "n" at position %d in Gettext expression.',
                    $this->position - 1,
                ));
            }
            return new CountVariable();
        }

        if ($char >= '0' && $char <= '9') {
            return $this->parseInteger();
        }

        throw new PluralResolutionException(sprintf(
            'Unexpected character "%s" at position %d in Gettext expression.',
            $char,
            $this->position,
        ));
    }

    private function parseInteger(): IntegerLiteral
    {
        $start = $this->position;
        while ($this->position < $this->length) {
            $char = $this->peek();
            if ($char < '0' || $char > '9') {
                break;
            }
            $this->position++;
        }

        $literal = substr($this->input, $start, $this->position - $start);
        if ($literal === '') {
            throw new PluralResolutionException(
                'Empty integer literal in Gettext expression.',
            );
        }

        return new IntegerLiteral((int) $literal);
    }

    private function consumeTwoCharOperator(string $operator): bool
    {
        $this->skipWhitespace();
        if ($this->position + self::TWO_CHAR_OPERATOR_LENGTH > $this->length) {
            return false;
        }

        if (substr($this->input, $this->position, self::TWO_CHAR_OPERATOR_LENGTH) !== $operator) {
            return false;
        }

        $this->position += self::TWO_CHAR_OPERATOR_LENGTH;

        return true;
    }

    private function consumeSingleCharOperator(string $operator): bool
    {
        $this->skipWhitespace();
        if ($this->position >= $this->length || $this->peek() !== $operator) {
            return false;
        }

        // Disambiguate single-char from two-char prefixes: `<` vs `<=`,
        // `>` vs `>=`, `!` vs `!=`, `=` vs `==`. The caller must invoke
        // the two-char variant first; here we just guard against false
        // positives on operators that share a leading char.
        $next = $this->position + 1 < $this->length ? $this->input[$this->position + 1] : '';
        if (
            ($operator === '<' && $next === '=')
            || ($operator === '>' && $next === '=')
            || ($operator === '!' && $next === '=')
            || ($operator === '=' && $next === '=')
        ) {
            return false;
        }

        $this->position++;

        return true;
    }

    private function peek(): string
    {
        return $this->input[$this->position];
    }

    private function skipWhitespace(): void
    {
        while ($this->position < $this->length) {
            $char = $this->peek();
            if ($char !== ' ' && $char !== "\t" && $char !== "\n" && $char !== "\r") {
                break;
            }
            $this->position++;
        }
    }

    private function isIdentifierChar(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || ($char >= '0' && $char <= '9')
            || $char === '_';
    }
}
