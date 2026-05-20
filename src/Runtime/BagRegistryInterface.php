<?php

declare(strict_types=1);

namespace G\Translator\Runtime;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Exception\Bag\EntryNotFoundException;

/**
 * Registry mapping locales to their respective translation bags.
 *
 * The registry is the runtime's data backbone: instead of binding
 * a `Translator` to a single bag (and therefore a single locale),
 * the translator holds a registry and queries it on demand. This
 * enables multilingual applications to switch locales at runtime
 * without rebuilding the translator.
 *
 * Implementations MAY support lazy loading: a registry MAY defer
 * the actual loading of a bag until its locale is requested for
 * the first time. This is an internal optimization and MUST NOT
 * change the contract's observable behavior.
 *
 * @see \G\Translator\Bag\TranslationBagInterface
 */
interface BagRegistryInterface
{
    /**
     * Registers a bag under the given locale.
     *
     * If a bag is already registered for the same locale, the new
     * one replaces it. This is intentional: it allows hot-reload
     * scenarios (e.g., reloading translations after a deployment).
     *
     * @param non-empty-string $locale The locale this bag carries.
     *        MUST match `$bag->locale()`; implementations MAY
     *        enforce this invariant.
     */
    public function register(string $locale, TranslationBagInterface $bag): void;

    /**
     * Returns the bag registered for the given locale.
     *
     * Strict accessor: throws when no bag matches. For lenient
     * lookups, use `find()`.
     *
     * @param non-empty-string $locale
     *
     * @throws EntryNotFoundException When no bag is registered
     *         for the requested locale.
     */
    public function get(string $locale): TranslationBagInterface;

    /**
     * Returns the bag for the given locale, or `null` when none
     * matches.
     *
     * Lenient counterpart of `get()`. Used by the runtime when
     * traversing the fallback chain (a missing locale is a normal
     * condition, not an error).
     *
     * @param non-empty-string $locale
     */
    public function find(string $locale): ?TranslationBagInterface;

    /**
     * Indicates whether a bag is registered for the given locale.
     *
     * @param non-empty-string $locale
     */
    public function has(string $locale): bool;

    /**
     * Returns the list of registered locales.
     *
     * Order is implementation-defined but MUST be stable across
     * calls when the registry is not mutated.
     *
     * @return list<non-empty-string>
     */
    public function locales(): array;
}
