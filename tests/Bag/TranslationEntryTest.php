<?php

declare(strict_types=1);

namespace G\Translator\Tests\Bag;

use G\Translator\Bag\TranslationEntry;
use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Bag\InvalidEntryException;
use G\Translator\Exception\TranslatorExceptionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationEntry::class)]
final class TranslationEntryTest extends TestCase
{
    public function testConstructorStoresAllFields(): void
    {
        $entry = new TranslationEntry(
            id: 'greeting',
            content: 'Hello',
            domain: 'messages',
            context: null,
            pluralForms: [],
            metadata: [],
        );

        self::assertSame('greeting', $entry->id());
        self::assertSame('Hello', $entry->content());
        self::assertSame('messages', $entry->domain());
        self::assertNull($entry->context());
        self::assertSame([], $entry->pluralForms());
        self::assertSame([], $entry->metadata());
    }

    public function testKeyEncodesNullContextWithSentinel(): void
    {
        $entry = new TranslationEntry(id: 'greeting');

        self::assertSame('messages::__none__::greeting', $entry->key());
    }

    public function testKeyEncodesNonNullContext(): void
    {
        $entry = new TranslationEntry(id: 'Post', domain: 'admin', context: 'verb');

        self::assertSame('admin::verb::Post', $entry->key());
    }

    public function testEmptyIdIsRejected(): void
    {
        $this->expectException(InvalidEntryException::class);
        new TranslationEntry(id: '');
    }

    public function testEmptyDomainIsRejected(): void
    {
        $this->expectException(InvalidEntryException::class);
        new TranslationEntry(id: 'x', domain: '');
    }

    public function testEmptyContextIsRejected(): void
    {
        $this->expectException(InvalidEntryException::class);
        new TranslationEntry(id: 'x', context: '');
    }

    public function testPluralFormsWithoutOtherAreRejected(): void
    {
        $this->expectException(InvalidEntryException::class);
        new TranslationEntry(
            id: 'apple',
            pluralForms: [PluralCategory::ONE->value => 'one apple'],
        );
    }

    public function testPluralFormsWithOtherAreAccepted(): void
    {
        $entry = new TranslationEntry(
            id: 'apple',
            pluralForms: [
                PluralCategory::ONE->value => 'one apple',
                PluralCategory::OTHER->value => '%count% apples',
            ],
        );

        self::assertCount(2, $entry->pluralForms());
        self::assertSame('one apple', $entry->pluralForms()[PluralCategory::ONE->value]);
    }

    public function testIsEmptyReturnsTrueForBlankEntry(): void
    {
        $entry = new TranslationEntry(id: 'x');
        self::assertTrue($entry->isEmpty());
    }

    public function testIsEmptyReturnsFalseWhenContentPresent(): void
    {
        $entry = new TranslationEntry(id: 'x', content: 'value');
        self::assertFalse($entry->isEmpty());
    }

    public function testIsEmptyReturnsFalseWhenAnyPluralFormPresent(): void
    {
        $entry = new TranslationEntry(
            id: 'apple',
            pluralForms: [
                PluralCategory::OTHER->value => 'apples',
            ],
        );

        self::assertFalse($entry->isEmpty());
    }

    public function testIsEmptyReturnsTrueWhenAllPluralFormsAreEmpty(): void
    {
        $entry = new TranslationEntry(
            id: 'apple',
            pluralForms: [
                PluralCategory::OTHER->value => '',
            ],
        );

        self::assertTrue($entry->isEmpty());
    }

    public function testWithContentReturnsNewInstance(): void
    {
        $entry = new TranslationEntry(id: 'x', content: 'old');
        $updated = $entry->withContent('new');

        self::assertNotSame($entry, $updated);
        self::assertSame('old', $entry->content());
        self::assertSame('new', $updated->content());
        self::assertSame($entry->key(), $updated->key());
    }

    public function testWithPluralFormsReturnsNewInstance(): void
    {
        $entry = new TranslationEntry(id: 'apple');
        $updated = $entry->withPluralForms([
            PluralCategory::OTHER->value => 'apples',
        ]);

        self::assertSame([], $entry->pluralForms());
        self::assertSame(['other' => 'apples'], $updated->pluralForms());
    }

    public function testWithMetadataReplacesPreviousMetadata(): void
    {
        $entry = new TranslationEntry(id: 'x', metadata: ['flag' => 'fuzzy']);
        $updated = $entry->withMetadata(['references' => ['file.php:12']]);

        self::assertSame(['flag' => 'fuzzy'], $entry->metadata());
        self::assertSame(['references' => ['file.php:12']], $updated->metadata());
    }

    public function testInvalidEntryExceptionImplementsRootMarkerInterface(): void
    {
        try {
            new TranslationEntry(id: '');
            self::fail('Expected InvalidEntryException');
        } catch (InvalidEntryException $e) {
            self::assertInstanceOf(TranslatorExceptionInterface::class, $e);
        }
    }

    /**
     * Happy-path contract test: any caller that respects the constructor's
     * PHPDoc types receives a fully populated entry. This locks in the
     * positive baseline so future refactors cannot quietly break it.
     */
    public function testHappyPathConstructionMatchingPhpDocTypes(): void
    {
        $entry = new TranslationEntry(
            id: 'apple.label',
            content: 'one apple',
            domain: 'inventory',
            context: 'noun',
            pluralForms: [
                PluralCategory::ONE->value => 'one apple',
                PluralCategory::OTHER->value => '{count} apples',
            ],
            metadata: [
                'references' => ['catalog.php:42'],
                'flags' => ['fuzzy'],
                'extracted' => 'Label shown in catalog grid',
            ],
        );

        self::assertSame('apple.label', $entry->id());
        self::assertSame('one apple', $entry->content());
        self::assertSame('inventory', $entry->domain());
        self::assertSame('noun', $entry->context());
        self::assertSame('one apple', $entry->pluralForms()[PluralCategory::ONE->value]);
        self::assertSame('{count} apples', $entry->pluralForms()[PluralCategory::OTHER->value]);
        self::assertSame(['catalog.php:42'], $entry->metadata()['references']);
        self::assertSame('inventory::noun::apple.label', $entry->key());
        self::assertFalse($entry->isEmpty());
    }

    /**
     * Design-decision documentation test: the constructor relies on the
     * PHPDoc `array<value-of<PluralCategory>, string>` type to keep
     * plural keys honest. PHPStan flags any literal violation at
     * analysis time, so the constructor does NOT re-validate at runtime.
     *
     * If a caller bypasses static analysis (e.g., reads keys from
     * untyped external input), garbage propagates into the entry —
     * by design. This is the intentional trade-off captured by the
     * Phase A review: validate at the type boundary, not twice.
     *
     * Should a future maintainer be tempted to add runtime validation
     * here, this test will fail and surface the design intent.
     */
    public function testRuntimeDoesNotValidatePluralCategoryKeysIntentionalGarbageInGarbageOut(): void
    {
        $forms = [
            PluralCategory::OTHER->value => '%count% apples',
            '__invalid_category__'       => 'this should not be here, but is',
        ];

        $entry = new TranslationEntry(id: 'apple', pluralForms: $forms);

        self::assertArrayHasKey('__invalid_category__', $entry->pluralForms());
        self::assertSame(
            'this should not be here, but is',
            $entry->pluralForms()['__invalid_category__'],
        );
    }
}
