<?php

declare(strict_types=1);

namespace G\Translator\Bag;

use Countable;
use G\Translator\Enum\MergeStrategy;
use G\Translator\Exception\Bag\DuplicateEntryException;
use G\Translator\Exception\Bag\EntryNotFoundException;
use G\Translator\Exception\Engine\MergeException;
use IteratorAggregate;
use Traversable;

/**
 * Contract for the in-memory translation collection.
 *
 * A `TranslationBag` is the canonical data structure produced by
 * parsers and consumed by serializers, the engine, and the
 * runtime. It groups all `TranslationEntry` instances belonging
 * to a single locale, possibly across multiple domains.
 *
 * Contrary to entries (which are immutable), a bag is intentionally
 * MUTABLE. This is a deliberate performance trade-off: parsers
 * populate bags with thousands of entries, and forcing immutability
 * here would mean cloning the whole structure on each insertion.
 * Safety is preserved by the immutability of entries: once an
 * entry is in the bag, it cannot drift.
 *
 * A bag is bound to a single locale (see `locale()`). Stacking
 * translations across multiple locales requires multiple bags,
 * not multiple entries with different locales inside one bag.
 *
 * @extends IteratorAggregate<non-empty-string, TranslationEntryInterface>
 *
 * @see \G\Translator\Bag\TranslationEntryInterface
 * @see \G\Translator\Enum\MergeStrategy
 */
interface TranslationBagInterface extends IteratorAggregate, Countable
{
    /**
     * Returns the locale this bag carries (e.g., `fr-FR`, `en`).
     *
     * @return non-empty-string
     */
    public function locale(): string;

    /**
     * Inserts a new entry in the bag.
     *
     * Strict insertion: if an entry with the same composite key
     * (id + domain + context) already exists, a
     * `DuplicateEntryException` is thrown. To overwrite, use
     * `set()`; to resolve collisions according to a policy, use
     * `merge()`.
     *
     * @throws DuplicateEntryException When the composite key
     *         already exists in the bag.
     */
    public function add(TranslationEntryInterface $entry): void;

    /**
     * Inserts or replaces an entry in the bag.
     *
     * Idempotent variant of `add()`: never throws on collision.
     * Use when overwriting is the intended semantic
     * (e.g., applying patches, user overrides).
     */
    public function set(TranslationEntryInterface $entry): void;

    /**
     * Retrieves an entry by its identity components.
     *
     * Strict accessor: throws when no entry matches. For lenient
     * lookups, use `find()`.
     *
     * @param non-empty-string $id The source identifier.
     * @param non-empty-string $domain The domain. Default:
     *        `'messages'`.
     * @param non-empty-string|null $context The disambiguation
     *        context, or `null` when looking up an entry without
     *        context.
     *
     * @throws EntryNotFoundException When no entry matches the
     *         requested identity.
     */
    public function get(
        string $id,
        string $domain = 'messages',
        ?string $context = null,
    ): TranslationEntryInterface;

    /**
     * Retrieves an entry by its identity components, or `null`
     * when none matches.
     *
     * Lenient counterpart of `get()`.
     *
     * @param non-empty-string $id
     * @param non-empty-string $domain
     * @param non-empty-string|null $context
     */
    public function find(
        string $id,
        string $domain = 'messages',
        ?string $context = null,
    ): ?TranslationEntryInterface;

    /**
     * Indicates whether the bag contains an entry matching the
     * given identity.
     *
     * @param non-empty-string $id
     * @param non-empty-string $domain
     * @param non-empty-string|null $context
     */
    public function has(
        string $id,
        string $domain = 'messages',
        ?string $context = null,
    ): bool;

    /**
     * Removes the entry matching the given identity.
     *
     * Idempotent: silently does nothing when no entry matches.
     *
     * @param non-empty-string $id
     * @param non-empty-string $domain
     * @param non-empty-string|null $context
     */
    public function remove(
        string $id,
        string $domain = 'messages',
        ?string $context = null,
    ): void;

    /**
     * Returns all entries optionally filtered by domain.
     *
     * When `$domain` is `null`, every entry is returned regardless
     * of its domain. Order is implementation-defined but MUST be
     * stable across calls when the bag is not mutated.
     *
     * @param non-empty-string|null $domain Optional domain filter.
     *
     * @return list<TranslationEntryInterface>
     */
    public function all(?string $domain = null): array;

    /**
     * Returns the list of distinct domains present in the bag.
     *
     * @return list<non-empty-string>
     */
    public function domains(): array;

    /**
     * Merges another bag into this one, resolving collisions
     * according to the given strategy.
     *
     * The incoming bag MUST share the same locale; otherwise the
     * implementation MUST throw `MergeException` to avoid silent
     * cross-locale contamination.
     *
     * On collision (same composite key), the configured
     * `MergeStrategy` decides the resolution. The default
     * (`THROW_ON_CONFLICT`) raises a `MergeException`, forcing the
     * caller to make an explicit choice.
     *
     * @param TranslationBagInterface $other The bag to merge in.
     * @param MergeStrategy $strategy Collision resolution strategy.
     *        Default: `MergeStrategy::default()`.
     *
     * @throws MergeException When locales mismatch, or when the
     *         strategy is `THROW_ON_CONFLICT` and a collision
     *         occurs, or when a custom strategy raises.
     */
    public function merge(
        TranslationBagInterface $other,
        MergeStrategy $strategy = MergeStrategy::THROW_ON_CONFLICT,
    ): void;

    /**
     * Returns an iterator over all entries.
     *
     * Keys are the composite keys produced by
     * `TranslationEntryInterface::key()`. Iteration order is
     * implementation-defined but MUST be stable across calls
     * when the bag is not mutated.
     *
     * @return Traversable<non-empty-string, TranslationEntryInterface>
     */
    public function getIterator(): Traversable;

    /**
     * Returns the total number of entries across all domains.
     */
    public function count(): int;
}
