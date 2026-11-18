<?php

declare(strict_types=1);

namespace G\Translator\Runtime;

use G\Translator\Exception\Runtime\InvalidLocaleException;

/**
 * Canonical implementation of `LocaleFallbackInterface`.
 *
 * Maintains a primary locale and an ordered list of additional
 * fallback locales. Optionally extends both with their canonical
 * parent locales (BCP-47 right-trim) when `autoParent` is enabled.
 *
 * The fully resolved chain returned by `chain()` is built lazily on
 * each call (the configuration surface is tiny, the runtime cost is
 * negligible) and never cached — any change to primary / chain /
 * autoParent is reflected immediately. This keeps the object free of
 * silent state.
 *
 * Locale normalization is delegated to `LocaleNormalizer`, which is
 * the single source of truth for BCP-47 handling across the package.
 *
 * Default state on construction:
 *   - Primary: `en` (a sensible last-resort default for libraries
 *     used without an explicit `setPrimary()` call).
 *   - Explicit chain: empty.
 *   - autoParent: OFF.
 */
final class LocaleFallback implements LocaleFallbackInterface
{
    /**
     * @var non-empty-string Default primary locale when none is set.
     */
    public const string DEFAULT_PRIMARY = 'en';

    /**
     * @var non-empty-string
     */
    private string $primary;

    /**
     * @var list<non-empty-string>
     */
    private array $explicitChain = [];

    private bool $autoParent = false;

    /**
     * @param non-empty-string $primary Initial primary locale. Will
     *        be normalized to canonical BCP-47.
     *
     * @throws InvalidLocaleException When the initial locale is
     *         malformed.
     */
    public function __construct(string $primary = self::DEFAULT_PRIMARY)
    {
        $this->primary = LocaleNormalizer::normalize($primary);
    }

    public function setPrimary(string $locale): void
    {
        $this->primary = LocaleNormalizer::normalize($locale);
    }

    public function primary(): string
    {
        return $this->primary;
    }

    /**
     * @param list<non-empty-string> $locales
     */
    public function setChain(array $locales): void
    {
        $normalized = [];
        $seen = [];

        foreach ($locales as $locale) {
            $canonical = LocaleNormalizer::normalize($locale);
            if ($canonical === $this->primary) {
                continue;
            }
            if (isset($seen[$canonical])) {
                continue;
            }
            $seen[$canonical] = true;
            $normalized[] = $canonical;
        }

        $this->explicitChain = $normalized;
    }

    /**
     * @return list<non-empty-string>
     */
    public function explicitChain(): array
    {
        return $this->explicitChain;
    }

    public function setAutoParent(bool $enabled): void
    {
        $this->autoParent = $enabled;
    }

    public function isAutoParentEnabled(): bool
    {
        return $this->autoParent;
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    public function chain(): array
    {
        $chain = [];
        $seen = [];

        $this->appendWithParents($this->primary, $chain, $seen);

        foreach ($this->explicitChain as $locale) {
            $this->appendWithParents($locale, $chain, $seen);
        }

        /** @var non-empty-list<non-empty-string> $chain */
        return $chain;
    }

    /**
     * @param non-empty-string $locale
     * @param list<non-empty-string> $chain
     * @param array<non-empty-string, true> $seen
     *
     * @param-out list<non-empty-string> $chain
     * @param-out array<non-empty-string, true> $seen
     */
    private function appendWithParents(string $locale, array &$chain, array &$seen): void
    {
        if (!isset($seen[$locale])) {
            $seen[$locale] = true;
            $chain[] = $locale;
        }

        if (!$this->autoParent) {
            return;
        }

        $current = $locale;
        while (($parent = LocaleNormalizer::parent($current)) !== null) {
            if (!isset($seen[$parent])) {
                $seen[$parent] = true;
                $chain[] = $parent;
            }
            $current = $parent;
        }
    }
}
