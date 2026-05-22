<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural;

use G\Translator\Enum\PluralCategory;

/**
 * Composite `PluralResolverInterface` delegating to a Gettext
 * expression resolver when an expression is registered for the
 * target locale, falling back to a CLDR-based resolver otherwise.
 *
 * Resolution priority on each call:
 *
 *   1. If the `GettextExpressionPluralResolver` has an expression
 *      registered for the locale → delegate to it.
 *   2. Else → delegate to the `CldrPluralResolver`.
 *
 * Both resolvers are injected via the constructor, making the
 * composite easy to test with stubs and trivial to extend (e.g.,
 * by chaining additional resolvers via decoration).
 *
 * The composite carries no state of its own; both delegates do
 * their own normalization, caching, and validation.
 */
final readonly class CompositePluralResolver implements PluralResolverInterface
{
    public function __construct(
        private GettextExpressionPluralResolver $gettext,
        private CldrPluralResolver $cldr,
    ) {
    }

    public function resolve(int $count, string $locale): PluralCategory
    {
        if ($this->gettext->has($locale)) {
            return $this->gettext->resolve($count, $locale);
        }

        return $this->cldr->resolve($count, $locale);
    }
}
