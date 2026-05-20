<?php

declare(strict_types=1);

namespace G\Translator\Runtime;

use G\Translator\Exception\Runtime\InvalidLocaleException;

/**
 * Resolves the ordered sequence of locales to consult when looking
 * up a translation.
 *
 * Two orthogonal mechanisms are exposed:
 *
 * 1. **Explicit fallback chain** — a developer-configured list of
 *    additional locales to try when the primary locale yields no
 *    result. Example: `['pl_PL', 'en_UK']`.
 *
 * 2. **Automatic parent resolution** (opt-in, OFF by default) —
 *    when enabled, each locale containing a region/script subtag
 *    is automatically extended with its parent locale. Example:
 *    `fr-FR` produces `fr-FR, fr` ; `zh-Hant-TW` produces
 *    `zh-Hant-TW, zh-Hant, zh`.
 *
 * Both mechanisms compose freely. With chain `['pl_PL', 'en_UK']`
 * and automatic parents ON, requesting `pl_PL` yields:
 *
 *     pl_PL → pl → en_UK → en
 *
 * Implementations MUST normalize locale forms: `pl_PL`, `pl-PL`
 * and `PL_pl` MUST be considered equivalent (canonical form is
 * BCP-47: `pl-PL`). Normalization is performed on inputs to
 * `setPrimary()`, `setChain()`, and on the output of `chain()`.
 *
 * @see \G\Translator\Runtime\TranslatorInterface
 * @see \G\Translator\Exception\Runtime\InvalidLocaleException
 */
interface LocaleFallbackInterface
{
    /**
     * Sets the primary locale, i.e. the first one to be consulted.
     *
     * @param non-empty-string $locale The primary locale, in any
     *        form accepted by the normalizer (BCP-47, underscore
     *        variant, etc.).
     *
     * @throws InvalidLocaleException When the locale string is
     *         malformed and cannot be normalized to a valid
     *         BCP-47 tag.
     */
    public function setPrimary(string $locale): void;

    /**
     * Returns the normalized primary locale.
     *
     * @return non-empty-string
     */
    public function primary(): string;

    /**
     * Sets the explicit fallback chain, replacing any previous
     * value.
     *
     * The primary locale (set via `setPrimary()`) MUST NOT be
     * included in the chain; implementations MAY silently dedupe
     * if it is.
     *
     * @param list<non-empty-string> $locales Ordered list of
     *        fallback locales.
     *
     * @throws InvalidLocaleException When any locale string is
     *         malformed.
     */
    public function setChain(array $locales): void;

    /**
     * Returns the configured explicit fallback chain (normalized,
     * deduplicated). Does NOT include the primary locale, nor
     * any automatically derived parents.
     *
     * @return list<non-empty-string>
     */
    public function explicitChain(): array;

    /**
     * Enables or disables automatic parent locale resolution.
     *
     * Default: `false` (OFF).
     */
    public function setAutoParent(bool $enabled): void;

    /**
     * Indicates whether automatic parent resolution is enabled.
     */
    public function isAutoParentEnabled(): bool;

    /**
     * Returns the fully resolved chain of locales to consult for
     * translation lookup, in order.
     *
     * The resolution order is:
     *   1. Primary locale.
     *   2. If `autoParent` is ON: parents of the primary, from
     *      most specific to most generic.
     *   3. Each entry of the explicit chain, in declaration order.
     *   4. If `autoParent` is ON: parents of each chain entry,
     *      interleaved after the entry.
     *
     * Duplicates are removed: a locale already produced earlier
     * in the chain is not emitted again.
     *
     * @return non-empty-list<non-empty-string> The complete
     *         resolution chain. Always contains at least the
     *         primary locale.
     */
    public function chain(): array;
}
