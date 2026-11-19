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
 * Rules are integer-only and cover every locale of the CLDR plural
 * data (227 language codes, generated from `plurals.xml`; a test checks
 * each of them against that file). CLDR also defines rules
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
 * Rules and locale table checked against the CLDR `plurals.xml`
 * (unicode-org/cldr, 2026-11-19).
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
        // Family: NoPlural
        'bm' => CldrPluralFamily::NoPlural,
        'bo' => CldrPluralFamily::NoPlural,
        'dz' => CldrPluralFamily::NoPlural,
        'hnj' => CldrPluralFamily::NoPlural,
        'id' => CldrPluralFamily::NoPlural,
        'ig' => CldrPluralFamily::NoPlural,
        'ii' => CldrPluralFamily::NoPlural,
        'in' => CldrPluralFamily::NoPlural,
        'ja' => CldrPluralFamily::NoPlural,
        'jbo' => CldrPluralFamily::NoPlural,
        'jv' => CldrPluralFamily::NoPlural,
        'jw' => CldrPluralFamily::NoPlural,
        'kde' => CldrPluralFamily::NoPlural,
        'kea' => CldrPluralFamily::NoPlural,
        'km' => CldrPluralFamily::NoPlural,
        'ko' => CldrPluralFamily::NoPlural,
        'lkt' => CldrPluralFamily::NoPlural,
        'lo' => CldrPluralFamily::NoPlural,
        'ms' => CldrPluralFamily::NoPlural,
        'my' => CldrPluralFamily::NoPlural,
        'nqo' => CldrPluralFamily::NoPlural,
        'osa' => CldrPluralFamily::NoPlural,
        'sah' => CldrPluralFamily::NoPlural,
        'ses' => CldrPluralFamily::NoPlural,
        'sg' => CldrPluralFamily::NoPlural,
        'su' => CldrPluralFamily::NoPlural,
        'th' => CldrPluralFamily::NoPlural,
        'to' => CldrPluralFamily::NoPlural,
        'tpi' => CldrPluralFamily::NoPlural,
        'wo' => CldrPluralFamily::NoPlural,
        'yo' => CldrPluralFamily::NoPlural,
        'yue' => CldrPluralFamily::NoPlural,
        'zh' => CldrPluralFamily::NoPlural,

        // Family: OneIsOne
        'af' => CldrPluralFamily::OneIsOne,
        'an' => CldrPluralFamily::OneIsOne,
        'asa' => CldrPluralFamily::OneIsOne,
        'ast' => CldrPluralFamily::OneIsOne,
        'az' => CldrPluralFamily::OneIsOne,
        'bal' => CldrPluralFamily::OneIsOne,
        'bem' => CldrPluralFamily::OneIsOne,
        'bez' => CldrPluralFamily::OneIsOne,
        'bg' => CldrPluralFamily::OneIsOne,
        'brx' => CldrPluralFamily::OneIsOne,
        'ce' => CldrPluralFamily::OneIsOne,
        'cgg' => CldrPluralFamily::OneIsOne,
        'chr' => CldrPluralFamily::OneIsOne,
        'ckb' => CldrPluralFamily::OneIsOne,
        'da' => CldrPluralFamily::OneIsOne,
        'de' => CldrPluralFamily::OneIsOne,
        'dv' => CldrPluralFamily::OneIsOne,
        'ee' => CldrPluralFamily::OneIsOne,
        'el' => CldrPluralFamily::OneIsOne,
        'en' => CldrPluralFamily::OneIsOne,
        'eo' => CldrPluralFamily::OneIsOne,
        'et' => CldrPluralFamily::OneIsOne,
        'eu' => CldrPluralFamily::OneIsOne,
        'fi' => CldrPluralFamily::OneIsOne,
        'fo' => CldrPluralFamily::OneIsOne,
        'fur' => CldrPluralFamily::OneIsOne,
        'fy' => CldrPluralFamily::OneIsOne,
        'gsw' => CldrPluralFamily::OneIsOne,
        'ha' => CldrPluralFamily::OneIsOne,
        'haw' => CldrPluralFamily::OneIsOne,
        'hu' => CldrPluralFamily::OneIsOne,
        'ia' => CldrPluralFamily::OneIsOne,
        'ie' => CldrPluralFamily::OneIsOne,
        'io' => CldrPluralFamily::OneIsOne,
        'jgo' => CldrPluralFamily::OneIsOne,
        'ji' => CldrPluralFamily::OneIsOne,
        'jmc' => CldrPluralFamily::OneIsOne,
        'ka' => CldrPluralFamily::OneIsOne,
        'kaj' => CldrPluralFamily::OneIsOne,
        'kcg' => CldrPluralFamily::OneIsOne,
        'kk' => CldrPluralFamily::OneIsOne,
        'kkj' => CldrPluralFamily::OneIsOne,
        'kl' => CldrPluralFamily::OneIsOne,
        'ks' => CldrPluralFamily::OneIsOne,
        'ksb' => CldrPluralFamily::OneIsOne,
        'ku' => CldrPluralFamily::OneIsOne,
        'ky' => CldrPluralFamily::OneIsOne,
        'lb' => CldrPluralFamily::OneIsOne,
        'lg' => CldrPluralFamily::OneIsOne,
        'lij' => CldrPluralFamily::OneIsOne,
        'mas' => CldrPluralFamily::OneIsOne,
        'mgo' => CldrPluralFamily::OneIsOne,
        'ml' => CldrPluralFamily::OneIsOne,
        'mn' => CldrPluralFamily::OneIsOne,
        'mr' => CldrPluralFamily::OneIsOne,
        'nah' => CldrPluralFamily::OneIsOne,
        'nb' => CldrPluralFamily::OneIsOne,
        'nd' => CldrPluralFamily::OneIsOne,
        'ne' => CldrPluralFamily::OneIsOne,
        'nl' => CldrPluralFamily::OneIsOne,
        'nn' => CldrPluralFamily::OneIsOne,
        'nnh' => CldrPluralFamily::OneIsOne,
        'no' => CldrPluralFamily::OneIsOne,
        'nr' => CldrPluralFamily::OneIsOne,
        'ny' => CldrPluralFamily::OneIsOne,
        'nyn' => CldrPluralFamily::OneIsOne,
        'om' => CldrPluralFamily::OneIsOne,
        'or' => CldrPluralFamily::OneIsOne,
        'os' => CldrPluralFamily::OneIsOne,
        'pap' => CldrPluralFamily::OneIsOne,
        'ps' => CldrPluralFamily::OneIsOne,
        'rm' => CldrPluralFamily::OneIsOne,
        'rof' => CldrPluralFamily::OneIsOne,
        'rwk' => CldrPluralFamily::OneIsOne,
        'saq' => CldrPluralFamily::OneIsOne,
        'sc' => CldrPluralFamily::OneIsOne,
        'sd' => CldrPluralFamily::OneIsOne,
        'sdh' => CldrPluralFamily::OneIsOne,
        'seh' => CldrPluralFamily::OneIsOne,
        'sn' => CldrPluralFamily::OneIsOne,
        'so' => CldrPluralFamily::OneIsOne,
        'sq' => CldrPluralFamily::OneIsOne,
        'ss' => CldrPluralFamily::OneIsOne,
        'ssy' => CldrPluralFamily::OneIsOne,
        'st' => CldrPluralFamily::OneIsOne,
        'sv' => CldrPluralFamily::OneIsOne,
        'sw' => CldrPluralFamily::OneIsOne,
        'syr' => CldrPluralFamily::OneIsOne,
        'ta' => CldrPluralFamily::OneIsOne,
        'te' => CldrPluralFamily::OneIsOne,
        'teo' => CldrPluralFamily::OneIsOne,
        'tig' => CldrPluralFamily::OneIsOne,
        'tk' => CldrPluralFamily::OneIsOne,
        'tn' => CldrPluralFamily::OneIsOne,
        'tr' => CldrPluralFamily::OneIsOne,
        'ts' => CldrPluralFamily::OneIsOne,
        'ug' => CldrPluralFamily::OneIsOne,
        'ur' => CldrPluralFamily::OneIsOne,
        'uz' => CldrPluralFamily::OneIsOne,
        've' => CldrPluralFamily::OneIsOne,
        'vo' => CldrPluralFamily::OneIsOne,
        'vun' => CldrPluralFamily::OneIsOne,
        'wae' => CldrPluralFamily::OneIsOne,
        'xh' => CldrPluralFamily::OneIsOne,
        'xog' => CldrPluralFamily::OneIsOne,
        'yi' => CldrPluralFamily::OneIsOne,

        // Family: ZeroOrOneIsOne
        'ak' => CldrPluralFamily::ZeroOrOneIsOne,
        'am' => CldrPluralFamily::ZeroOrOneIsOne,
        'as' => CldrPluralFamily::ZeroOrOneIsOne,
        'bho' => CldrPluralFamily::ZeroOrOneIsOne,
        'bn' => CldrPluralFamily::ZeroOrOneIsOne,
        'csw' => CldrPluralFamily::ZeroOrOneIsOne,
        'doi' => CldrPluralFamily::ZeroOrOneIsOne,
        'fa' => CldrPluralFamily::ZeroOrOneIsOne,
        'ff' => CldrPluralFamily::ZeroOrOneIsOne,
        'gu' => CldrPluralFamily::ZeroOrOneIsOne,
        'guw' => CldrPluralFamily::ZeroOrOneIsOne,
        'hi' => CldrPluralFamily::ZeroOrOneIsOne,
        'hy' => CldrPluralFamily::ZeroOrOneIsOne,
        'kab' => CldrPluralFamily::ZeroOrOneIsOne,
        'kn' => CldrPluralFamily::ZeroOrOneIsOne,
        'kok' => CldrPluralFamily::ZeroOrOneIsOne,
        'kok-Latn' => CldrPluralFamily::ZeroOrOneIsOne,
        'ln' => CldrPluralFamily::ZeroOrOneIsOne,
        'mg' => CldrPluralFamily::ZeroOrOneIsOne,
        'nso' => CldrPluralFamily::ZeroOrOneIsOne,
        'pa' => CldrPluralFamily::ZeroOrOneIsOne,
        'pcm' => CldrPluralFamily::ZeroOrOneIsOne,
        'si' => CldrPluralFamily::ZeroOrOneIsOne,
        'tg' => CldrPluralFamily::ZeroOrOneIsOne,
        'ti' => CldrPluralFamily::ZeroOrOneIsOne,
        'vi' => CldrPluralFamily::ZeroOrOneIsOne,
        'wa' => CldrPluralFamily::ZeroOrOneIsOne,
        'zu' => CldrPluralFamily::ZeroOrOneIsOne,

        // Family: OneIsOneMillionIsMany
        'ca' => CldrPluralFamily::OneIsOneMillionIsMany,
        'es' => CldrPluralFamily::OneIsOneMillionIsMany,
        'gl' => CldrPluralFamily::OneIsOneMillionIsMany,
        'it' => CldrPluralFamily::OneIsOneMillionIsMany,
        'lld' => CldrPluralFamily::OneIsOneMillionIsMany,
        'pt-PT' => CldrPluralFamily::OneIsOneMillionIsMany,
        'scn' => CldrPluralFamily::OneIsOneMillionIsMany,
        'vec' => CldrPluralFamily::OneIsOneMillionIsMany,

        // Family: ZeroOrOneIsOneMillionIsMany
        'fr' => CldrPluralFamily::ZeroOrOneIsOneMillionIsMany,
        'pt' => CldrPluralFamily::ZeroOrOneIsOneMillionIsMany,

        // Family: SlavicEast
        'be' => CldrPluralFamily::SlavicEast,
        'ru' => CldrPluralFamily::SlavicEast,
        'uk' => CldrPluralFamily::SlavicEast,

        // Family: SlavicSouth
        'bs' => CldrPluralFamily::SlavicSouth,
        'hr' => CldrPluralFamily::SlavicSouth,
        'sh' => CldrPluralFamily::SlavicSouth,
        'sr' => CldrPluralFamily::SlavicSouth,

        // Family: Polish
        'pl' => CldrPluralFamily::Polish,

        // Family: SlavicWest
        'cs' => CldrPluralFamily::SlavicWest,
        'sk' => CldrPluralFamily::SlavicWest,

        // Family: Romanian
        'mo' => CldrPluralFamily::Romanian,
        'ro' => CldrPluralFamily::Romanian,

        // Family: Arabic
        'ar' => CldrPluralFamily::Arabic,
        'ars' => CldrPluralFamily::Arabic,

        // Family: Hebrew
        'he' => CldrPluralFamily::Hebrew,
        'iu' => CldrPluralFamily::Hebrew,
        'iw' => CldrPluralFamily::Hebrew,
        'naq' => CldrPluralFamily::Hebrew,
        'sat' => CldrPluralFamily::Hebrew,
        'se' => CldrPluralFamily::Hebrew,
        'sma' => CldrPluralFamily::Hebrew,
        'smi' => CldrPluralFamily::Hebrew,
        'smj' => CldrPluralFamily::Hebrew,
        'smn' => CldrPluralFamily::Hebrew,
        'sms' => CldrPluralFamily::Hebrew,

        // Family: Slovenian
        'dsb' => CldrPluralFamily::Slovenian,
        'hsb' => CldrPluralFamily::Slovenian,
        'sl' => CldrPluralFamily::Slovenian,

        // Family: Latvian
        'lv' => CldrPluralFamily::Latvian,
        'prg' => CldrPluralFamily::Latvian,

        // Family: Lithuanian
        'lt' => CldrPluralFamily::Lithuanian,

        // Family: Irish
        'ga' => CldrPluralFamily::Irish,

        // Family: Welsh
        'cy' => CldrPluralFamily::Welsh,

        // Family: Maltese
        'mt' => CldrPluralFamily::Maltese,

        // Family: OneEndsInOne
        'is' => CldrPluralFamily::OneEndsInOne,
        'mk' => CldrPluralFamily::OneEndsInOne,

        // Family: Filipino
        'ceb' => CldrPluralFamily::Filipino,
        'fil' => CldrPluralFamily::Filipino,
        'tl' => CldrPluralFamily::Filipino,

        // Family: ZeroOneOther
        'blo' => CldrPluralFamily::ZeroOneOther,
        'cv' => CldrPluralFamily::ZeroOneOther,
        'ksh' => CldrPluralFamily::ZeroOneOther,
        'lag' => CldrPluralFamily::ZeroOneOther,

        // Family: ScottishGaelic
        'gd' => CldrPluralFamily::ScottishGaelic,

        // Family: Breton
        'br' => CldrPluralFamily::Breton,

        // Family: Manx
        'gv' => CldrPluralFamily::Manx,

        // Family: Cornish
        'kw' => CldrPluralFamily::Cornish,

        // Family: Samogitian
        'sgs' => CldrPluralFamily::Samogitian,

        // Family: Tachelhit
        'shi' => CldrPluralFamily::Tachelhit,

        // Family: CentralAtlasTamazight
        'tzm' => CldrPluralFamily::CentralAtlasTamazight,
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
            CldrPluralFamily::OneEndsInOne   => $n % 10 === 1 && $n % 100 !== 11
                ? PluralCategory::ONE
                : PluralCategory::OTHER,
            CldrPluralFamily::Filipino       => $n <= 3 || !in_array($n % 10, [4, 6, 9], true)
                ? PluralCategory::ONE
                : PluralCategory::OTHER,
            CldrPluralFamily::ZeroOneOther   => match ($n) {
                0       => PluralCategory::ZERO,
                1       => PluralCategory::ONE,
                default => PluralCategory::OTHER,
            },
            CldrPluralFamily::ScottishGaelic        => $this->ruleScottishGaelic($n),
            CldrPluralFamily::Breton                => $this->ruleBreton($n),
            CldrPluralFamily::Manx                  => $this->ruleManx($n),
            CldrPluralFamily::Cornish               => $this->ruleCornish($n),
            CldrPluralFamily::Samogitian            => $this->ruleSamogitian($n),
            CldrPluralFamily::Tachelhit             => match (true) {
                $n <= 1  => PluralCategory::ONE,
                $n <= 10 => PluralCategory::FEW,
                default  => PluralCategory::OTHER,
            },
            CldrPluralFamily::CentralAtlasTamazight => $n <= 1 || ($n >= 11 && $n <= 99)
                ? PluralCategory::ONE
                : PluralCategory::OTHER,
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

    private function ruleScottishGaelic(int $n): PluralCategory
    {
        return match (true) {
            $n === 1 || $n === 11                           => PluralCategory::ONE,
            $n === 2 || $n === 12                           => PluralCategory::TWO,
            ($n >= 3 && $n <= 10) || ($n >= 13 && $n <= 19) => PluralCategory::FEW,
            default                                         => PluralCategory::OTHER,
        };
    }

    private function ruleBreton(int $n): PluralCategory
    {
        $tens = $n % self::TENS_MODULUS;
        $hundreds = $n % self::HUNDREDS_MODULUS;
        $teens = ($hundreds >= 10 && $hundreds <= 19)
            || ($hundreds >= 70 && $hundreds <= 79)
            || $hundreds >= 90;

        return match (true) {
            $tens === 1 && !in_array($hundreds, [11, 71, 91], true) => PluralCategory::ONE,
            $tens === 2 && !in_array($hundreds, [12, 72, 92], true) => PluralCategory::TWO,
            in_array($tens, [3, 4, 9], true) && !$teens              => PluralCategory::FEW,
            $this->ruleMillionIsMany($n) !== null                    => PluralCategory::MANY,
            default                                                  => PluralCategory::OTHER,
        };
    }

    private function ruleManx(int $n): PluralCategory
    {
        return match (true) {
            $n % self::TENS_MODULUS === 1                                 => PluralCategory::ONE,
            $n % self::TENS_MODULUS === 2                                 => PluralCategory::TWO,
            in_array($n % self::HUNDREDS_MODULUS, [0, 20, 40, 60, 80], true) => PluralCategory::FEW,
            default                                                       => PluralCategory::OTHER,
        };
    }

    private function ruleCornish(int $n): PluralCategory
    {
        $hundreds = $n % self::HUNDREDS_MODULUS;
        $hundredThousands = $n % 100_000;
        $isTwo = in_array($hundreds, [2, 22, 42, 62, 82], true)
            || ($n % 1000 === 0
                && (($hundredThousands >= 1000 && $hundredThousands <= 20_000)
                    || in_array($hundredThousands, [40_000, 60_000, 80_000], true)))
            || ($n !== 0 && $n % self::MILLION === 100_000);

        return match (true) {
            $n === 0                                                => PluralCategory::ZERO,
            $n === 1                                                => PluralCategory::ONE,
            $isTwo                                                  => PluralCategory::TWO,
            in_array($hundreds, [3, 23, 43, 63, 83], true)          => PluralCategory::FEW,
            in_array($hundreds, [1, 21, 41, 61, 81], true)          => PluralCategory::MANY,
            default                                                 => PluralCategory::OTHER,
        };
    }

    private function ruleSamogitian(int $n): PluralCategory
    {
        $tens = $n % self::TENS_MODULUS;
        $hundreds = $n % self::HUNDREDS_MODULUS;
        $teens = $hundreds >= 11 && $hundreds <= 19;

        return match (true) {
            $tens === 1 && $hundreds !== 11 => PluralCategory::ONE,
            $n === 2                        => PluralCategory::TWO,
            $tens >= 2 && !$teens           => PluralCategory::FEW,
            default                         => PluralCategory::OTHER,
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
