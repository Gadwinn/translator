<?php

declare(strict_types=1);

namespace G\Translator\Runtime;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Exception\Bag\EntryNotFoundException;

/**
 * Canonical implementation of `BagRegistryInterface`.
 *
 * A thin, in-memory map of locale → `TranslationBag`. Locale keys are
 * normalized to canonical BCP-47 via `LocaleNormalizer`, ensuring that
 * lookups are consistent regardless of how the locale was originally
 * spelled (`pl_PL`, `pl-pl`, `PL-PL` all resolve to the same slot).
 *
 * Registration replaces any previously stored bag for the same locale.
 * This is deliberate: hot-reload scenarios (reloading translations
 * after a deployment) MUST work without prior teardown.
 *
 * No lazy loading is performed by this default implementation:
 * `register()` stores the bag immediately and `find()` does not call
 * back into any loader. Lazy-loading registries are expected to be
 * implemented as separate classes also conforming to the interface.
 */
final class BagRegistry implements BagRegistryInterface
{
    /**
     * @var array<non-empty-string, TranslationBagInterface>
     */
    private array $bags = [];

    public function register(string $locale, TranslationBagInterface $bag): void
    {
        $canonical = LocaleNormalizer::normalize($locale);
        $this->bags[$canonical] = $bag;
    }

    public function get(string $locale): TranslationBagInterface
    {
        $bag = $this->find($locale);
        if ($bag === null) {
            throw new EntryNotFoundException(sprintf(
                'No translation bag registered for locale "%s".',
                $locale,
            ));
        }

        return $bag;
    }

    public function find(string $locale): ?TranslationBagInterface
    {
        $canonical = LocaleNormalizer::normalize($locale);

        return $this->bags[$canonical] ?? null;
    }

    public function has(string $locale): bool
    {
        return isset($this->bags[LocaleNormalizer::normalize($locale)]);
    }

    /**
     * @return list<non-empty-string>
     */
    public function locales(): array
    {
        /** @var list<non-empty-string> $locales */
        $locales = array_keys($this->bags);

        return $locales;
    }
}
