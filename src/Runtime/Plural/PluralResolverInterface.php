<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural;

use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Runtime\PluralResolutionException;

/**
 * Resolves the appropriate plural category for a given count and
 * locale.
 *
 * Implementations are Strategy variants:
 *
 * - **CLDR resolver** (default) — uses the Unicode CLDR plural
 *   rules embedded in the package. Covers every locale defined
 *   by CLDR.
 *
 * - **Gettext expression resolver** — evaluates a Gettext-style
 *   plural formula (e.g., `n != 1`, `(n%10==1 && n%100!=11)`)
 *   read from the `Plural-Forms` header of a PO file. Used when
 *   a source explicitly declares its own plural rule.
 *
 * The runtime MAY combine both: prefer the Gettext expression
 * when present on a parsed source, otherwise fall back to CLDR.
 *
 * Resolvers MUST be stateless and side-effect free. They MAY
 * memoize their internal lookup tables.
 *
 * @see \G\Translator\Enum\PluralCategory
 * @see \G\Translator\Exception\Runtime\PluralResolutionException
 */
interface PluralResolverInterface
{
    /**
     * Returns the CLDR plural category matching the given count
     * for the given locale.
     *
     * Implementations that cannot determine a specific category
     * (unknown locale, malformed expression) MUST throw
     * `PluralResolutionException` rather than silently returning
     * `OTHER`. Callers SHOULD catch the exception and apply their
     * own fallback policy.
     *
     * @param int $count The count to categorize. MAY be negative
     *        or zero.
     * @param non-empty-string $locale The locale whose plural
     *        rules apply (e.g., `fr-FR`, `ru`).
     *
     * @throws PluralResolutionException When the resolver cannot
     *         determine a category (missing rule for the locale,
     *         malformed Gettext expression, etc.).
     */
    public function resolve(int $count, string $locale): PluralCategory;
}
