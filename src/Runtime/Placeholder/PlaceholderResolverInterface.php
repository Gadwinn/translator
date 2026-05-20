<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Placeholder;

use G\Translator\Exception\Runtime\InvalidPlaceholderValueException;
use Stringable;

/**
 * Hydrates a translated string by replacing its placeholders with
 * the provided values.
 *
 * Four placeholder syntaxes are supported simultaneously, BUT a
 * single translated string MUST use only one family at a time.
 * Mixing families within the same string yields undefined
 * behavior:
 *
 * - **Percent-named** — `%name%` (Gettext / Poedit style).
 *   Escape literal `%` by doubling it (`%%`).
 *
 * - **Brace-named** — `{name}` (ICU MessageFormat / Symfony 6+
 *   style). Escape literal `{` and `}` by doubling them
 *   (`{{`, `}}`).
 *
 * - **Positional** — `%s`, `%d`, etc. (sprintf-style, sequential
 *   consumption of integer-keyed params).
 *
 * - **Indexed positional** — `%1$s`, `%2$d`, etc.
 *   (sprintf-style, explicit indices).
 *
 * The percent-based families (`%name%`, `%s`, `%1$s`) all use
 * `%%` for escaping; the brace-based family uses `{{` / `}}`.
 *
 * The `$params` array MUST contain only values typed as `scalar`
 * (string, int, float, bool) or `Stringable`. Any other type —
 * `null`, plain objects without `__toString`, arrays, resources —
 * MUST trigger an `InvalidPlaceholderValueException`. This strict
 * policy is intentional: silently coercing such values would mask
 * upstream bugs (e.g., an unassigned variable being passed as a
 * placeholder).
 *
 * Resolvers MUST be stateless.
 */
interface PlaceholderResolverInterface
{
    /**
     * Returns a copy of the given string with all placeholders
     * substituted by the matching values from `$params`.
     *
     * Resolution rules:
     *
     * - Named placeholders (`%name%`, `{name}`) match string keys
     *   in `$params`.
     * - Sequential positional placeholders (`%s`, `%d`) consume
     *   integer-keyed values in declaration order.
     * - Indexed positional placeholders (`%N$s`) match the
     *   integer key `N - 1` in `$params` (sprintf convention,
     *   1-based in the format string, 0-based in the array).
     * - Unmatched placeholders MUST be left untouched in the
     *   output (no exception thrown). This is intentional: a
     *   missing placeholder is a translation issue, not a
     *   runtime error.
     *
     * @param string $message The translated string, possibly
     *        containing placeholders.
     * @param array<int|string, scalar|Stringable> $params
     *        The values to inject. `null` and non-`Stringable`
     *        objects are NOT accepted (see strict policy above).
     *
     * @return string The hydrated string.
     *
     * @throws InvalidPlaceholderValueException When a value
     *         referenced by an active placeholder is `null` or
     *         cannot be coerced to a string (non-`Stringable`
     *         object, array, resource).
     */
    public function resolve(string $message, array $params): string;
}
