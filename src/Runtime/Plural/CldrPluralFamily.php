<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural;

/**
 * Internal enum identifying a CLDR plural rule family.
 *
 * Each case represents a group of locales sharing the exact same
 * integer-only plural-form selection logic. The shape of the family
 * is encoded by `CldrPluralResolver::resolveForFamily()`; the
 * locale → family mapping is encoded by
 * `CldrPluralResolver::LOCALE_TO_FAMILY`.
 *
 * Not part of the public API — exposed at the namespace level only
 * because the constant table in `CldrPluralResolver` requires the
 * type to be accessible from declarations.
 *
 * @see \G\Translator\Runtime\Plural\CldrPluralResolver
 */
enum CldrPluralFamily
{
    /**
     * Locales with no plural distinction (Japanese, Chinese, …).
     */
    case NoPlural;

    /**
     * Germanic / simple-plural locales (`n == 1 ? one : other`).
     */
    case OneIsOne;

    /**
     * French-style (`n == 0 || n == 1 ? one : other`).
     */
    case ZeroOrOneIsOne;

    /**
     * East-Slavic (Russian, Ukrainian, Belarusian, Serbian, Croatian,
     * Bosnian).
     */
    case SlavicEast;

    /**
     * Polish (distinct from the East-Slavic shape: `n == 1` is
     * always `one`, regardless of modular arithmetic).
     */
    case Polish;

    /**
     * West-Slavic (Czech, Slovak).
     */
    case SlavicWest;

    /**
     * Romanian.
     */
    case Romanian;

    /**
     * Arabic (uses all six CLDR categories).
     */
    case Arabic;

    /**
     * Hebrew.
     */
    case Hebrew;

    /**
     * Slovenian.
     */
    case Slovenian;

    /**
     * Latvian.
     */
    case Latvian;

    /**
     * Lithuanian.
     */
    case Lithuanian;

    /**
     * Irish Gaelic.
     */
    case Irish;

    /**
     * Welsh (uses all six CLDR categories).
     */
    case Welsh;

    /**
     * Maltese.
     */
    case Maltese;
}
