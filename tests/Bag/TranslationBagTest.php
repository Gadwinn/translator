<?php

declare(strict_types=1);

namespace G\Translator\Tests\Bag;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Enum\MergeStrategy;
use G\Translator\Exception\Bag\DuplicateEntryException;
use G\Translator\Exception\Bag\EntryNotFoundException;
use G\Translator\Exception\Engine\MergeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationBag::class)]
final class TranslationBagTest extends TestCase
{
    public function testLocaleIsExposed(): void
    {
        $bag = new TranslationBag('fr-FR');
        self::assertSame('fr-FR', $bag->locale());
    }

    public function testAddInsertsEntry(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(id: 'hello', content: 'bonjour'));

        self::assertCount(1, $bag);
        self::assertTrue($bag->has('hello'));
    }

    public function testAddThrowsOnDuplicate(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(id: 'hello', content: 'bonjour'));

        $this->expectException(DuplicateEntryException::class);
        $bag->add(new TranslationEntry(id: 'hello', content: 'salut'));
    }

    public function testSetIsIdempotent(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->set(new TranslationEntry(id: 'hello', content: 'bonjour'));
        $bag->set(new TranslationEntry(id: 'hello', content: 'salut'));

        self::assertSame('salut', $bag->get('hello')->content());
    }

    public function testGetThrowsWhenMissing(): void
    {
        $bag = new TranslationBag('fr-FR');
        $this->expectException(EntryNotFoundException::class);
        $bag->get('missing');
    }

    public function testFindReturnsNullWhenMissing(): void
    {
        $bag = new TranslationBag('fr-FR');
        self::assertNull($bag->find('missing'));
    }

    public function testHasReturnsFalseWhenMissing(): void
    {
        $bag = new TranslationBag('fr-FR');
        self::assertFalse($bag->has('missing'));
    }

    public function testRemoveIsIdempotent(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(id: 'hello'));
        $bag->remove('hello');
        $bag->remove('hello');

        self::assertFalse($bag->has('hello'));
        self::assertCount(0, $bag);
    }

    public function testAllWithoutDomainReturnsEverything(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(id: 'one', domain: 'messages'));
        $bag->add(new TranslationEntry(id: 'two', domain: 'admin'));

        self::assertCount(2, $bag->all());
    }

    public function testAllFilteredByDomain(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(id: 'one', domain: 'messages'));
        $bag->add(new TranslationEntry(id: 'two', domain: 'admin'));

        $admin = $bag->all('admin');
        self::assertCount(1, $admin);
        self::assertSame('two', $admin[0]->id());
    }

    public function testDomainsReturnsDistinctValues(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(id: 'one', domain: 'messages'));
        $bag->add(new TranslationEntry(id: 'two', domain: 'admin'));
        $bag->add(new TranslationEntry(id: 'three', domain: 'admin'));

        $domains = $bag->domains();
        sort($domains);
        self::assertSame(['admin', 'messages'], $domains);
    }

    public function testIterationKeysAreCompositeKeys(): void
    {
        $bag = new TranslationBag('fr-FR');
        $entry = new TranslationEntry(id: 'hello');
        $bag->add($entry);

        foreach ($bag as $key => $value) {
            self::assertSame($entry->key(), $key);
            self::assertSame($entry, $value);
        }
    }

    public function testMergeThrowsOnLocaleMismatch(): void
    {
        $bag = new TranslationBag('fr-FR');
        $other = new TranslationBag('en-GB');

        $this->expectException(MergeException::class);
        $bag->merge($other);
    }

    public function testMergeThrowOnConflictIsDefault(): void
    {
        $a = new TranslationBag('fr-FR');
        $a->add(new TranslationEntry(id: 'hi', content: 'bonjour'));

        $b = new TranslationBag('fr-FR');
        $b->add(new TranslationEntry(id: 'hi', content: 'salut'));

        $this->expectException(MergeException::class);
        $a->merge($b);
    }

    public function testMergeReplaceOverwrites(): void
    {
        $a = new TranslationBag('fr-FR');
        $a->add(new TranslationEntry(id: 'hi', content: 'bonjour'));

        $b = new TranslationBag('fr-FR');
        $b->add(new TranslationEntry(id: 'hi', content: 'salut'));

        $a->merge($b, MergeStrategy::REPLACE);
        self::assertSame('salut', $a->get('hi')->content());
    }

    public function testMergeKeepExistingPreservesOriginal(): void
    {
        $a = new TranslationBag('fr-FR');
        $a->add(new TranslationEntry(id: 'hi', content: 'bonjour'));

        $b = new TranslationBag('fr-FR');
        $b->add(new TranslationEntry(id: 'hi', content: 'salut'));

        $a->merge($b, MergeStrategy::KEEP_EXISTING);
        self::assertSame('bonjour', $a->get('hi')->content());
    }

    public function testMergeAddsNewEntriesRegardlessOfStrategy(): void
    {
        $a = new TranslationBag('fr-FR');
        $a->add(new TranslationEntry(id: 'hi', content: 'bonjour'));

        $b = new TranslationBag('fr-FR');
        $b->add(new TranslationEntry(id: 'bye', content: 'au revoir'));

        $a->merge($b, MergeStrategy::THROW_ON_CONFLICT);
        self::assertSame('au revoir', $a->get('bye')->content());
    }

    /**
     * Composite-key disambiguation invariant: two entries sharing
     * `(id, domain)` but differing in `context` are distinct slots.
     * The bag MUST store them side by side, retrieve each by its full
     * identity, and refuse to confuse them during merge under the
     * strict `THROW_ON_CONFLICT` strategy.
     *
     * Locks in the design intent of the composite key against any
     * future refactor that might collapse it to (id, domain) only.
     */
    public function testContextDisambiguatesOtherwiseIdenticalEntries(): void
    {
        $bag = new TranslationBag('en-GB');
        $bag->add(new TranslationEntry(id: 'Post', context: null, content: 'Post (no context)'));
        $bag->add(new TranslationEntry(id: 'Post', context: 'noun', content: 'Post (noun)'));
        $bag->add(new TranslationEntry(id: 'Post', context: 'verb', content: 'Post (verb)'));

        self::assertCount(3, $bag);
        self::assertSame('Post (no context)', $bag->get('Post')->content());
        self::assertSame('Post (noun)', $bag->get('Post', context: 'noun')->content());
        self::assertSame('Post (verb)', $bag->get('Post', context: 'verb')->content());

        // Merging a bag carrying a fourth context yields a fourth entry,
        // not a collision: distinct contexts == distinct composite keys.
        $other = new TranslationBag('en-GB');
        $other->add(new TranslationEntry(id: 'Post', context: 'adjective', content: 'Post (adj)'));
        $bag->merge($other, MergeStrategy::THROW_ON_CONFLICT);

        self::assertCount(4, $bag);
        self::assertSame('Post (adj)', $bag->get('Post', context: 'adjective')->content());
    }

    /**
     * Companion to the disambiguation test: a duplicate (id, domain,
     * context) tuple MUST collide under `THROW_ON_CONFLICT`, even
     * when other entries with the same id + domain coexist in the
     * destination bag under different contexts.
     */
    public function testMergeRespectsFullCompositeKeyOnCollision(): void
    {
        $bag = new TranslationBag('en-GB');
        $bag->add(new TranslationEntry(id: 'Post', context: 'noun', content: 'Post (noun)'));
        $bag->add(new TranslationEntry(id: 'Post', context: 'verb', content: 'Post (verb)'));

        $other = new TranslationBag('en-GB');
        $other->add(new TranslationEntry(id: 'Post', context: 'noun', content: 'Post (other noun)'));

        $this->expectException(MergeException::class);
        $bag->merge($other, MergeStrategy::THROW_ON_CONFLICT);
    }
}
