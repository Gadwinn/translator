<?php

declare(strict_types=1);

namespace G\Translator\Engine\Strategy;

use G\Translator\Bag\TranslationEntryInterface;
use G\Translator\Enum\MergeStrategy;
use G\Translator\Exception\Engine\MergeException;

/**
 * Contract for a concrete merge strategy implementation.
 *
 * The `MergeStrategy` enum is the DECLARATIVE selector ("I want
 * REPLACE"); a `MergeStrategyHandlerInterface` is its EFFECTIVE
 * implementation. The engine looks up a handler via a resolver
 * each time it needs to resolve a collision between two entries.
 *
 * This split (Strategy + Resolver) is what makes the merge layer
 * Open/Closed: third parties MAY register additional handlers
 * (potentially bound to custom enum cases) without modifying the
 * engine code.
 *
 * Handlers MUST be stateless and reusable: the same instance is
 * called repeatedly, possibly across multiple merge operations.
 *
 * @see \G\Translator\Enum\MergeStrategy
 * @see \G\Translator\Engine\Strategy\MergeStrategyResolverInterface
 * @see \G\Translator\Exception\Engine\MergeException
 */
interface MergeStrategyHandlerInterface
{
    /**
     * Resolves a collision between two entries sharing the same
     * composite key.
     *
     * Returns the entry that should remain in the target bag.
     * Implementations MAY return `$existing`, `$incoming`, or a
     * new entry derived from both (e.g., a content-aware merge).
     *
     * The returned entry MUST share the same composite key as the
     * collided pair; returning an entry with a different key
     * breaks the bag's internal invariants.
     *
     * @param TranslationEntryInterface $existing The entry
     *        already present in the target bag.
     * @param TranslationEntryInterface $incoming The entry from
     *        the bag being merged in.
     *
     * @return TranslationEntryInterface The winning entry.
     *
     * @throws MergeException When the handler cannot resolve the
     *         collision (e.g., strict mode, irreconcilable
     *         conflict, custom validation failure).
     */
    public function resolve(
        TranslationEntryInterface $existing,
        TranslationEntryInterface $incoming,
    ): TranslationEntryInterface;

    /**
     * Returns the `MergeStrategy` enum case this handler
     * implements.
     *
     * Used by the resolver to register the handler against its
     * declarative selector.
     */
    public function strategy(): MergeStrategy;
}
