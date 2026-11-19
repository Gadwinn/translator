<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural;

use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Runtime\PluralResolutionException;
use G\Translator\Runtime\LocaleNormalizer;

/**
 * `PluralResolverInterface` implementation backed by the Unicode
 * CLDR plural rules embedded directly in PHP.
 *
 * Rules are integer-only and cover the vast majority of locales
 * deployed in real-world applications. CLDR also defines rules
 * for fractional counts (`v`, `w`, `f`, `t` variables in the
 * spec) but the package's resolver interface only accepts `int`
 * counts, so those are not implemented.
 *
 * Rules are grouped into a small set of "families" — locales
 * sharing the same plural shape. The `LOCALE_TO_FAMILY` table
 * resolves a normalized locale to its family; the canonical
 * `match` in `resolveForFamily()` evaluates the family rule
 * against the count.
 *
 * To extend coverage, add the locale (and any parent) to the
 * `LOCALE_TO_FAMILY` table, or register a new family in
 * `Family` and `resolveForFamily()`.
 *
 * Rules checked against the CLDR `plurals.xml` (unicode-org/cldr,
 * 2026-11-19).
 *
 * Embedded rules are STABLE DATA: they are version-controlled in
 * source, not loaded from JSON at runtime. This matches the
 * zero-dependency philosophy and guarantees deterministic behavior
 * across deployments.
 */
final class CldrPluralResolver implements PluralResolverInterface
{
    /**
     * @var int Modulus used for slavic and similar tens-based rules.
     */
    private const int TENS_MODULUS = 10;

    /**
     * @var int Modulus used for slavic and similar hundreds-based rules.
     */
    private const int HUNDREDS_MODULUS = 100;

    /**
     * @var int Counts that are an exact multiple of this take `many`
     *      in French, Spanish, Italian… ("un million de", "un millón de").
     */
    private const int MILLION = 1_000_000;

    /**
     * Mapping of canonical BCP-47 locale (or just language subtag)
     * to the rule family that locale follows.
     *
     * Lookups are performed first on the full locale, then on the
     * language root. This lets callers express both broad rules
     * (`'fr' => Family::ZeroOrOneIsOne`) and locale-specific overrides
     * (`'pt-PT' => Family::OneIsOneMillionIsMany` while `'pt'` follows the
     * Brazilian rules).
     *
     * @var array<non-empty-string, CldrPluralFamily>
     */
    private const array LOCALE_TO_FAMILY = [
        // Family: NoPlural (everything is OTHER)
        'ja' => CldrPluralFamily::NoPlural,
        'ko' => CldrPluralFamily::NoPlural,
        'zh' => CldrPluralFamily::NoPlural,
        'th' => CldrPluralFamily::NoPlural,
        'id' => CldrPluralFamily::NoPlural,
        'ms' => CldrPluralFamily::NoPlural,
        'lo' => CldrPluralFamily::NoPlural,
        'km' => CldrPluralFamily::NoPlural,
        'my' => CldrPluralFamily::NoPlural,

        // Family: OneIsOne (Germanic + many Romance / Indo-European simple plurals)
        'en' => CldrPluralFamily::OneIsOne,
        'de' => CldrPluralFamily::OneIsOne,
        'nl' => CldrPluralFamily::OneIsOne,
        'sv' => CldrPluralFamily::OneIsOne,
        'da' => CldrPluralFamily::OneIsOne,
        'no' => CldrPluralFamily::OneIsOne,
        'nb' => CldrPluralFamily::OneIsOne,
        'nn' => CldrPluralFamily::OneIsOne,
        'fi' => CldrPluralFamily::OneIsOne,
        'et' => CldrPluralFamily::OneIsOne,
        'el' => CldrPluralFamily::OneIsOne,
        'bg' => CldrPluralFamily::OneIsOne,
        'hu' => CldrPluralFamily::OneIsOne,
        'tr' => CldrPluralFamily::OneIsOne,
        'sq' => CldrPluralFamily::OneIsOne,
        'sw' => CldrPluralFamily::OneIsOne,
        'eu' => CldrPluralFamily::OneIsOne,
        'af' => CldrPluralFamily::OneIsOne,

        // Family: OneIsOneMillionIsMany
        'it'    => CldrPluralFamily::OneIsOneMillionIsMany,
        'es'    => CldrPluralFamily::OneIsOneMillionIsMany,
        'ca'    => CldrPluralFamily::OneIsOneMillionIsMany,
        'gl'    => CldrPluralFamily::OneIsOneMillionIsMany,
        'pt-PT' => CldrPluralFamily::OneIsOneMillionIsMany,

        // Family: ZeroOrOneIsOne
        'hi' => CldrPluralFamily::ZeroOrOneIsOne,
        'fa' => CldrPluralFamily::ZeroOrOneIsOne,
        'hy' => CldrPluralFamily::ZeroOrOneIsOne,
        'vi' => CldrPluralFamily::ZeroOrOneIsOne,

        // Family: ZeroOrOneIsOneMillionIsMany
        'fr' => CldrPluralFamily::ZeroOrOneIsOneMillionIsMany,
        'pt' => CldrPluralFamily::ZeroOrOneIsOneMillionIsMany,

        // Family: SlavicEast (Russian-style)
        'ru' => CldrPluralFamily::SlavicEast,
        'uk' => CldrPluralFamily::SlavicEast,
        'be' => CldrPluralFamily::SlavicEast,

        // Family: SlavicSouth (Serbo-Croatian)
        'sr' => CldrPluralFamily::SlavicSouth,
        'hr' => CldrPluralFamily::SlavicSouth,
        'bs' => CldrPluralFamily::SlavicSouth,

        // Family: Polish
        'pl' => CldrPluralFamily::Polish,

        // Family: SlavicWest (Czech / Slovak)
        'cs' => CldrPluralFamily::SlavicWest,
        'sk' => CldrPluralFamily::SlavicWest,

        // Family: Romanian
        'ro' => CldrPluralFamily::Romanian,

        // Family: Arabic
        'ar' => CldrPluralFamily::Arabic,

        // Family: Hebrew
        'he' => CldrPluralFamily::Hebrew,
        'iw' => CldrPluralFamily::Hebrew,

        // Family: Slovenian
        'sl' => CldrPluralFamily::Slovenian,

        // Family: Latvian
        'lv' => CldrPluralFamily::Latvian,

        // Family: Lithuanian
        'lt' => CldrPluralFamily::Lithuanian,

        // Family: Irish
        'ga' => CldrPluralFamily::Irish,

        // Family: Welsh
        'cy' => CldrPluralFamily::Welsh,

        // Family: Maltese
        'mt' => CldrPluralFamily::Maltese,
    ];

    public function resolve(int $count, string $locale): PluralCategory
    {
        $canonical = LocaleNormalizer::normalize($locale);
        $family = $this->familyFor($canonical);

        return $this->resolveForFamily($family, $count);
    }

    /**
     * @param non-empty-string $locale Canonical BCP-47 locale.
     */
    private function familyFor(string $locale): CldrPluralFamily
    {
        if (isset(self::LOCALE_TO_FAMILY[$locale])) {
            return self::LOCALE_TO_FAMILY[$locale];
        }

        $parent = LocaleNormalizer::parent($locale);
        while ($parent !== null) {
            if (isset(self::LOCALE_TO_FAMILY[$parent])) {
                return self::LOCALE_TO_FAMILY[$parent];
            }
            $parent = LocaleNormalizer::parent($parent);
        }

        throw new PluralResolutionException(sprintf(
            'No CLDR plural rule registered for locale "%s".',
            $locale,
        ));
    }

    private function resolveForFamily(CldrPluralFamily $family, int $count): PluralCategory
    {
        $n = abs($count);

        return match ($family) {
            CldrPluralFamily::NoPlural       => PluralCategory::OTHER,
            CldrPluralFamily::OneIsOne       => $this->ruleOneIsOne($n),
            CldrPluralFamily::ZeroOrOneIsOne => $this->ruleZeroOrOneIsOne($n),
            CldrPluralFamily::OneIsOneMillionIsMany       => $this->ruleMillionIsMany($n)
                ?? $this->ruleOneIsOne($n),
            CldrPluralFamily::ZeroOrOneIsOneMillionIsMany => $this->ruleMillionIsMany($n)
                ?? $this->ruleZeroOrOneIsOne($n),
            CldrPluralFamily::SlavicEast     => $this->ruleSlavicEast($n),
            CldrPluralFamily::SlavicSouth    => $this->ruleSlavicSouth($n),
            CldrPluralFamily::Polish         => $this->rulePolish($n),
            CldrPluralFamily::SlavicWest     => $this->ruleSlavicWest($n),
            CldrPluralFamily::Romanian       => $this->ruleRomanian($n),
            CldrPluralFamily::Arabic         => $this->ruleArabic($n),
            CldrPluralFamily::Hebrew         => $this->ruleHebrew($n),
            CldrPluralFamily::Slovenian      => $this->ruleSlovenian($n),
            CldrPluralFamily::Latvian        => $this->ruleLatvian($n),
            CldrPluralFamily::Lithuanian     => $this->ruleLithuanian($n),
            CldrPluralFamily::Irish          => $this->ruleIrish($n),
            CldrPluralFamily::Welsh          => $this->ruleWelsh($n),
            CldrPluralFamily::Maltese        => $this->ruleMaltese($n),
        };
    }

    private function ruleOneIsOne(int $n): PluralCategory
    {
        return $n === 1 ? PluralCategory::ONE : PluralCategory::OTHER;
    }

    private function ruleZeroOrOneIsOne(int $n): PluralCategory
    {
        return ($n === 0 || $n === 1) ? PluralCategory::ONE : PluralCategory::OTHER;
    }

    private function ruleMillionIsMany(int $n): ?PluralCategory
    {
        return $n !== 0 && $n % self::MILLION === 0 ? PluralCategory::MANY : null;
    }

    private function ruleSlavicSouth(int $n): PluralCategory
    {
        $category = $this->ruleSlavicEast($n);

        return $category === PluralCategory::MANY ? PluralCategory::OTHER : $category;
    }

    private function ruleSlavicEast(int $n): PluralCategory
    {
        $tens = $n % self::TENS_MODULUS;
        $hundreds = $n % self::HUNDREDS_MODULUS;

        if ($tens === 1 && $hundreds !== 11) {
            return PluralCategory::ONE;
        }

        if ($tens >= 2 && $tens <= 4 && ($hundreds < 12 || $hundreds > 14)) {
            return PluralCategory::FEW;
        }

        return PluralCategory::MANY;
    }

    private function rulePolish(int $n): PluralCategory
    {
        if ($n === 1) {
            return PluralCategory::ONE;
        }

        $tens = $n % self::TENS_MODULUS;
        $hundreds = $n % self::HUNDREDS_MODULUS;

        if ($tens >= 2 && $tens <= 4 && ($hundreds < 12 || $hundreds > 14)) {
            return PluralCategory::FEW;
        }

        return PluralCategory::MANY;
    }

    private function ruleSlavicWest(int $n): PluralCategory
    {
        if ($n === 1) {
            return PluralCategory::ONE;
        }

        if ($n >= 2 && $n <= 4) {
            return PluralCategory::FEW;
        }

        return PluralCategory::OTHER;
    }

    private function ruleRomanian(int $n): PluralCategory
    {
        if ($n === 1) {
            return PluralCategory::ONE;
        }

        $hundreds = $n % self::HUNDREDS_MODULUS;
        if ($n === 0 || ($hundreds >= 1 && $hundreds <= 19)) {
            return PluralCategory::FEW;
        }

        return PluralCategory::OTHER;
    }

    private function ruleArabic(int $n): PluralCategory
    {
        if ($n === 0) {
            return PluralCategory::ZERO;
        }
        if ($n === 1) {
            return PluralCategory::ONE;
        }
        if ($n === 2) {
            return PluralCategory::TWO;
        }

        $hundreds = $n % self::HUNDREDS_MODULUS;
        if ($hundreds >= 3 && $hundreds <= 10) {
            return PluralCategory::FEW;
        }
        if ($hundreds >= 11) {
            return PluralCategory::MANY;
        }

        return PluralCategory::OTHER;
    }

    private function ruleHebrew(int $n): PluralCategory
    {
        if ($n === 1) {
            return PluralCategory::ONE;
        }
        if ($n === 2) {
            return PluralCategory::TWO;
        }

        // CLDR 42 dropped the former `many` (20, 30, 40…)
        return PluralCategory::OTHER;
    }

    private function ruleSlovenian(int $n): PluralCategory
    {
        $hundreds = $n % self::HUNDREDS_MODULUS;

        if ($hundreds === 1) {
            return PluralCategory::ONE;
        }
        if ($hundreds === 2) {
            return PluralCategory::TWO;
        }
        if ($hundreds === 3 || $hundreds === 4) {
            return PluralCategory::FEW;
        }

        return PluralCategory::OTHER;
    }

    private function ruleLatvian(int $n): PluralCategory
    {
        $tens = $n % self::TENS_MODULUS;
        $hundreds = $n % self::HUNDREDS_MODULUS;

        if ($tens === 0 || ($hundreds >= 11 && $hundreds <= 19)) {
            return PluralCategory::ZERO;
        }
        if ($tens === 1) {
            return PluralCategory::ONE;
        }

        return PluralCategory::OTHER;
    }

    private function ruleLithuanian(int $n): PluralCategory
    {
        $tens = $n % self::TENS_MODULUS;
        $hundreds = $n % self::HUNDREDS_MODULUS;
        $inElevenToNineteen = $hundreds >= 11 && $hundreds <= 19;

        if ($tens === 1 && !$inElevenToNineteen) {
            return PluralCategory::ONE;
        }
        if ($tens >= 2 && !$inElevenToNineteen) {
            return PluralCategory::FEW;
        }

        return PluralCategory::OTHER;
    }

    private function ruleIrish(int $n): PluralCategory
    {
        if ($n === 1) {
            return PluralCategory::ONE;
        }
        if ($n === 2) {
            return PluralCategory::TWO;
        }
        if ($n >= 3 && $n <= 6) {
            return PluralCategory::FEW;
        }
        if ($n >= 7 && $n <= 10) {
            return PluralCategory::MANY;
        }

        return PluralCategory::OTHER;
    }

    private function ruleWelsh(int $n): PluralCategory
    {
        return match (true) {
            $n === 0           => PluralCategory::ZERO,
            $n === 1           => PluralCategory::ONE,
            $n === 2           => PluralCategory::TWO,
            $n === 3           => PluralCategory::FEW,
            $n === 6           => PluralCategory::MANY,
            default            => PluralCategory::OTHER,
        };
    }

    private function ruleMaltese(int $n): PluralCategory
    {
        if ($n === 1) {
            return PluralCategory::ONE;
        }
        if ($n === 2) {
            return PluralCategory::TWO;
        }

        $hundreds = $n % self::HUNDREDS_MODULUS;
        if ($n === 0 || ($hundreds >= 3 && $hundreds <= 10)) {
            return PluralCategory::FEW;
        }
        if ($hundreds >= 11 && $hundreds <= 19) {
            return PluralCategory::MANY;
        }

        return PluralCategory::OTHER;
    }
}
