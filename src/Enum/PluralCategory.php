<?php

declare(strict_types=1);

namespace G\Translator\Enum;

/**
 * CLDR (Unicode Common Locale Data Repository) plural categories.
 *
 * Defines the six canonical plural buckets used by the runtime to
 * select the appropriate plural form for a given count, according
 * to a language's plural rules. Not all locales use all categories:
 * English uses only `ONE` and `OTHER`; Russian uses `ONE`, `FEW`,
 * `MANY`, `OTHER`; Arabic uses all six; etc.
 *
 * The string-backed value of each case matches the exact CLDR
 * identifier (`zero`, `one`, `two`, `few`, `many`, `other`) and is
 * the canonical key used to address plural variants in a
 * `TranslationEntry`.
 *
 * @see https://www.unicode.org/cldr/charts/latest/supplemental/language_plural_rules.html
 * @see \G\Translator\Exception\Runtime\PluralResolutionException
 */
enum PluralCategory: string
{
    /**
     * Used for languages distinguishing "zero" as its own form
     * (e.g., Arabic, Welsh).
     */
    case ZERO = 'zero';

    /**
     * Singular form. Used in nearly every locale.
     */
    case ONE = 'one';

    /**
     * Used for languages with a dual form (e.g., Arabic, Welsh,
     * Slovenian).
     */
    case TWO = 'two';

    /**
     * Used for languages requiring a distinct form for small counts
     * (e.g., Russian "2–4", Polish "2–4").
     */
    case FEW = 'few';

    /**
     * Used for languages requiring a distinct form for large or
     * "indefinite" counts (e.g., Russian "5+", Arabic "11–99").
     */
    case MANY = 'many';

    /**
     * Fallback category. Every locale supports `OTHER`; it is the
     * default bucket when no more specific category applies. A
     * `TranslationEntry` with plural forms MUST always provide at
     * least an `OTHER` form.
     */
    case OTHER = 'other';

    /**
     * Returns the mandatory fallback category every locale must
     * implement.
     *
     * Centralizes the "required category" rule (DRY) so that
     * validators and serializers reference a single source of truth.
     */
    public static function fallback(): self
    {
        return self::OTHER;
    }

    /**
     * Returns the CLDR-ordered list of categories.
     *
     * The order matches the canonical CLDR spec: zero, one, two,
     * few, many, other. Useful for serializers that need a stable,
     * deterministic output ordering (e.g., PO `msgstr[N]`, XLIFF
     * `<plural-form>`).
     *
     * @return non-empty-list<self>
     */
    public static function orderedList(): array
    {
        return [
            self::ZERO,
            self::ONE,
            self::TWO,
            self::FEW,
            self::MANY,
            self::OTHER,
        ];
    }
}
