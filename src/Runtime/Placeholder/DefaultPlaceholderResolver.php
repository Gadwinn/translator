<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Placeholder;

use G\Translator\Exception\Runtime\InvalidPlaceholderValueException;
use Stringable;

/**
 * Canonical implementation of `PlaceholderResolverInterface`.
 *
 * Supports the four placeholder families documented on the
 * interface. The resolver detects which family is active in the
 * incoming message and applies a SINGLE substitution pass — the
 * contract forbids mixing families in one translated string, so
 * the family detection is deterministic and the others are left
 * untouched.
 *
 * ## Family detection priority
 *
 * When a string accidentally mixes families (undefined behavior
 * per the interface, but observed in practice with partial
 * translations), this implementation applies the following
 * deterministic priority:
 *
 *   1. **sprintf positional** — any token matching
 *      `%[index$]?[flags][width][.precision]<sdfxXobeucg>`. Wins
 *      over both named families when present.
 *   2. **percent-named** — `%name%` where `name` is an identifier.
 *   3. **brace-named** — `{name}` where `name` is an identifier.
 *   4. **none** — the message contains no placeholder; returned
 *      verbatim (modulo escape collapsing in the brace family).
 *
 * The sprintf-first ordering is justified by sprintf tokens being
 * far less likely to appear as literal text than `%word%` or
 * `{word}` patterns (which sometimes appear in domain content),
 * so giving them priority surfaces caller bugs more loudly.
 *
 * ## Escape semantics
 *
 * - Escapes are resolved in the same single pass as the tokens:
 *   percent-based families turn `%%` into `%`, the brace-named
 *   family turns `{{` into `{` and `}}` into `}`. A substituted value
 *   is never scanned again, so a value containing `%`, `%%` or `{{`
 *   is inserted exactly as given and cannot produce a new token.
 *
 * ## Strict value policy
 *
 * Every value referenced by an active placeholder MUST be either
 * `scalar` (string, int, float, bool) or implement `Stringable`.
 * `null`, arrays, plain objects, and resources are rejected with
 * `InvalidPlaceholderValueException`. The translator does NOT
 * silently coerce them to `''`, because that masks upstream bugs
 * (typically: an unassigned variable being passed in).
 */
final class DefaultPlaceholderResolver implements PlaceholderResolverInterface
{
    /**
     * @var non-empty-string Regex matching an sprintf-style token.
     *      Conversion characters cover the integer, float, string
     *      and ad-hoc bases used in practice. The trailing negative
     *      lookahead `(?!\w*%)` prevents the regex from biting into
     *      the leading bytes of a `%name%` placeholder whose first
     *      identifier letter happens to be a conversion char (e.g.,
     *      the `%c` inside `%count%`, or the `%s` inside `%subject%`).
     */
    private const string SPRINTF_TOKEN_REGEX = '/%%|%(?:(\d+)\$)?([+\-]?\d*(?:\.\d+)?)?([sdfxXobeucg])(?!\w*%)/';

    /**
     * @var non-empty-string Regex matching `%name%` placeholders OR
     *      the `%%` escape, used by the percent-named family.
     */
    private const string PERCENT_NAMED_REGEX = '/%%|%([A-Za-z_][A-Za-z0-9_]*)%/';

    /**
     * @var non-empty-string Regex matching `{name}` placeholders OR
     *      the `{{` / `}}` escapes, used by the brace-named family.
     */
    private const string BRACE_NAMED_REGEX = '/\{\{|\}\}|\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /**
     * @var non-empty-string Regex used purely for family detection of
     *      the sprintf family. Distinguishes `%s`, `%d`, etc. from
     *      the `%%` escape and from `%name%` placeholders. The same
     *      `(?!\w*%)` lookahead protects against percent-named patterns
     *      whose first identifier letter is a conversion char.
     */
    private const string SPRINTF_DETECTION_REGEX = '/(?<!%)%(?:\d+\$)?[+\-]?\d*(?:\.\d+)?[sdfxXobeucg](?!\w*%)/';

    /**
     * @var non-empty-string Regex used purely for family detection of
     *      the percent-named family.
     */
    private const string PERCENT_NAMED_DETECTION_REGEX = '/(?<!%)%[A-Za-z_][A-Za-z0-9_]*%/';

    /**
     * @var non-empty-string Regex used purely for family detection of
     *      the brace-named family.
     */
    private const string BRACE_NAMED_DETECTION_REGEX = '/(?<![\{\}])\{[A-Za-z_][A-Za-z0-9_]*\}/';

    /**
     * @param array<int|string, scalar|Stringable> $params
     */
    public function resolve(string $message, array $params): string
    {
        if ($message === '') {
            return $message;
        }

        return match ($this->detectFamily($message)) {
            PlaceholderFamily::Sprintf     => $this->applySprintf($message, $params),
            PlaceholderFamily::PercentName => $this->applyPercentNamed($message, $params),
            PlaceholderFamily::BraceName   => $this->applyBraceNamed($message, $params),
            PlaceholderFamily::None        => $message,
        };
    }

    private function detectFamily(string $message): PlaceholderFamily
    {
        if (preg_match(self::SPRINTF_DETECTION_REGEX, $message) === 1) {
            return PlaceholderFamily::Sprintf;
        }

        if (preg_match(self::PERCENT_NAMED_DETECTION_REGEX, $message) === 1) {
            return PlaceholderFamily::PercentName;
        }

        if (preg_match(self::BRACE_NAMED_DETECTION_REGEX, $message) === 1) {
            return PlaceholderFamily::BraceName;
        }

        return PlaceholderFamily::None;
    }

    /**
     * @param array<int|string, scalar|Stringable> $params
     */
    private function applySprintf(string $message, array $params): string
    {
        $autoIndex = 0;
        $substituted = preg_replace_callback(
            self::SPRINTF_TOKEN_REGEX,
            function (array $match) use ($params, &$autoIndex): string {
                // Escapes are resolved here, never after the substitution:
                // a "%%" inside a substituted value must stay as it is.
                if ($match[0] === '%%') {
                    return '%';
                }

                $explicitIndex = $match[1] ?? '';
                $flags = $match[2] ?? '';
                $conversion = $match[3] ?? '';

                if ($explicitIndex !== '') {
                    $index = (int) $explicitIndex - 1;
                } else {
                    $index = $autoIndex;
                    $autoIndex++;
                }

                if (!array_key_exists($index, $params)) {
                    return $match[0];
                }

                $value = $this->coerceToString($params[$index], $match[0]);

                return sprintf('%' . $flags . $conversion, $value);
            },
            $message,
        );

        if ($substituted === null) {
            return $message;
        }

        return $substituted;
    }

    /**
     * @param array<int|string, scalar|Stringable> $params
     */
    private function applyPercentNamed(string $message, array $params): string
    {
        $substituted = preg_replace_callback(
            self::PERCENT_NAMED_REGEX,
            function (array $match) use ($params): string {
                if ($match[0] === '%%') {
                    return '%';
                }

                $name = $match[1] ?? '';
                if ($name === '' || !array_key_exists($name, $params)) {
                    return $match[0];
                }

                return $this->coerceToString($params[$name], $match[0]);
            },
            $message,
        );

        if ($substituted === null) {
            return $message;
        }

        return $substituted;
    }

    /**
     * @param array<int|string, scalar|Stringable> $params
     */
    private function applyBraceNamed(string $message, array $params): string
    {
        $substituted = preg_replace_callback(
            self::BRACE_NAMED_REGEX,
            function (array $match) use ($params): string {
                if ($match[0] === '{{' || $match[0] === '}}') {
                    return $match[0][0];
                }

                $name = $match[1] ?? '';
                if ($name === '' || !array_key_exists($name, $params)) {
                    return $match[0];
                }

                return $this->coerceToString($params[$name], $match[0]);
            },
            $message,
        );

        if ($substituted === null) {
            return $message;
        }

        return $substituted;
    }

    private function coerceToString(mixed $value, string $token): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidPlaceholderValueException(sprintf(
            'Placeholder "%s" requires a scalar or Stringable value; got %s.',
            $token,
            get_debug_type($value),
        ));
    }
}
