<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Placeholder;

/**
 * Internal enum identifying the placeholder family detected in a
 * translated string.
 *
 * Not part of the public API — exposed at the namespace level only
 * because `DefaultPlaceholderResolver` uses it in a `match`
 * expression. Consumers SHOULD NOT rely on its existence; the
 * enum may be made `private` to the resolver in a future refactor.
 *
 * @see \G\Translator\Runtime\Placeholder\DefaultPlaceholderResolver
 */
enum PlaceholderFamily
{
    /**
     * sprintf-style positional tokens (`%s`, `%d`, `%1$s`, …).
     */
    case Sprintf;

    /**
     * Gettext / Poedit `%name%` placeholders.
     */
    case PercentName;

    /**
     * ICU / Symfony 6+ `{name}` placeholders.
     */
    case BraceName;

    /**
     * No placeholder detected; the message is returned verbatim.
     */
    case None;
}
