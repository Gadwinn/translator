<?php

declare(strict_types=1);

namespace G\Translator\Bag;

use G\Translator\Enum\MergeStrategy;
use G\Translator\Exception\Bag\DuplicateEntryException;
use G\Translator\Exception\Bag\EntryNotFoundException;
use G\Translator\Exception\Engine\MergeException;
use Traversable;

/**
 * Canonical implementation of `TranslationBagInterface`.
 *
 * Stores entries in an associative array keyed by their composite key
 * (`{domain}::{context|__none__}::{id}`). Insertion order is preserved
 * by PHP's hash table, satisfying the "stable iteration order" clause
 * of the contract.
 *
 * Mutable by design: parsers populate bags with potentially thousands
 * of entries, and forcing immutability here would mean cloning the
 * entire backing array on each `add()`. Safety is preserved by the
 * immutability of entries themselves — see `TranslationEntry`.
 *
 * The bag is bound to a single locale; merging another bag with a
 * different locale raises `MergeException` to prevent silent
 * cross-locale contamination.
 */
final class TranslationBag implements TranslationBagInterface
{
    /**
     * @var array<non-empty-string, TranslationEntryInterface>
     */
    private array $entries = [];

    /**
     * @param non-empty-string $locale The locale this bag carries.
     */
    public function __construct(private readonly string $locale)
    {
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function add(TranslationEntryInterface $entry): void
    {
        $key = $entry->key();
        if (isset($this->entries[$key])) {
            throw new DuplicateEntryException(sprintf(
                'Translation entry with key "%s" already exists in bag for locale "%s".',
                $key,
                $this->locale,
            ));
        }

        $this->entries[$key] = $entry;
    }

    public function set(TranslationEntryInterface $entry): void
    {
        $this->entries[$entry->key()] = $entry;
    }

    public function get(string $id, string $domain = 'messages', ?string $context = null): TranslationEntryInterface
    {
        $entry = $this->find($id, $domain, $context);
        if ($entry === null) {
            throw new EntryNotFoundException(sprintf(
                'Translation entry not found for id "%s", domain "%s", context "%s" in locale "%s".',
                $id,
                $domain,
                $context ?? '<none>',
                $this->locale,
            ));
        }

        return $entry;
    }

    public function find(
        string $id,
        string $domain = 'messages',
        ?string $context = null,
    ): ?TranslationEntryInterface {
        $key = TranslationEntry::buildKey($domain, $context, $id);

        return $this->entries[$key] ?? null;
    }

    public function has(string $id, string $domain = 'messages', ?string $context = null): bool
    {
        return isset($this->entries[TranslationEntry::buildKey($domain, $context, $id)]);
    }

    public function remove(string $id, string $domain = 'messages', ?string $context = null): void
    {
        unset($this->entries[TranslationEntry::buildKey($domain, $context, $id)]);
    }

    /**
     * @return list<TranslationEntryInterface>
     */
    public function all(?string $domain = null): array
    {
        if ($domain === null) {
            return array_values($this->entries);
        }

        $filtered = [];
        foreach ($this->entries as $entry) {
            if ($entry->domain() === $domain) {
                $filtered[] = $entry;
            }
        }

        return $filtered;
    }

    /**
     * @return list<non-empty-string>
     */
    public function domains(): array
    {
        $seen = [];
        foreach ($this->entries as $entry) {
            $seen[$entry->domain()] = true;
        }

        /** @var list<non-empty-string> $domains */
        $domains = array_keys($seen);

        return $domains;
    }

    public function merge(
        TranslationBagInterface $other,
        MergeStrategy $strategy = MergeStrategy::THROW_ON_CONFLICT,
    ): void {
        if ($other->locale() !== $this->locale) {
            throw new MergeException(sprintf(
                'Cannot merge bags with mismatched locales ("%s" into "%s").',
                $other->locale(),
                $this->locale,
            ));
        }

        foreach ($other as $entry) {
            $key = $entry->key();
            if (!isset($this->entries[$key])) {
                $this->entries[$key] = $entry;
                continue;
            }

            match ($strategy) {
                MergeStrategy::THROW_ON_CONFLICT => throw new MergeException(sprintf(
                    'Merge conflict on key "%s" in locale "%s"; strategy is THROW_ON_CONFLICT.',
                    $key,
                    $this->locale,
                )),
                MergeStrategy::REPLACE => $this->entries[$key] = $entry,
                MergeStrategy::KEEP_EXISTING => null,
            };
        }
    }

    /**
     * @return Traversable<non-empty-string, TranslationEntryInterface>
     */
    public function getIterator(): Traversable
    {
        yield from $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
