<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Roundtrip;

use G\Translator\Bag\TranslationEntryInterface;
use G\Translator\Io\Parser\PoParser;
use G\Translator\Io\Serializer\PoSerializer;
use G\Translator\Io\Source\FileSource;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end PO round-trip tests.
 *
 * Each test parses a real-world `.po` fixture, serializes the
 * resulting bag back to PO, parses the serialized output a second
 * time, and asserts that the two parsed bags are equivalent on
 * every supported field. The intermediate PO strings may differ in
 * formatting (whitespace, comment ordering), but the parsed
 * semantics MUST be identical.
 */
#[CoversClass(PoParser::class)]
#[CoversClass(PoSerializer::class)]
final class PoRoundtripTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../../Fixtures/po/sample.po';

    public function testFixtureRoundtripPreservesAllSemantics(): void
    {
        $parser = new PoParser();
        $serializer = new PoSerializer();

        $bag1 = $parser->parse(new FileSource(self::FIXTURE_PATH), 'fr-FR');
        $serialized = $serializer->serialize($bag1);
        $bag2 = $parser->parse(new StringSource($serialized, extension: 'po'), 'fr-FR');

        self::assertSame($bag1->count(), $bag2->count());

        foreach ($bag1 as $key => $entry) {
            self::assertTrue(
                $bag2->has($entry->id(), $entry->domain(), $entry->context()),
                "Entry $key missing after round-trip",
            );

            $other = $bag2->get($entry->id(), $entry->domain(), $entry->context());
            $this->assertEntriesEquivalent($entry, $other);
        }
    }

    public function testRoundtripPreservesHeaderFields(): void
    {
        $parser = new PoParser();
        $serializer = new PoSerializer();

        $bag1 = $parser->parse(new FileSource(self::FIXTURE_PATH), 'fr-FR');
        $bag2 = $parser->parse(
            new StringSource($serializer->serialize($bag1), extension: 'po'),
            'fr-FR',
        );

        $first1 = $bag1->all()[0];
        $first2 = $bag2->all()[0];
        $header1 = $first1->metadata()['po-header'] ?? null;
        $header2 = $first2->metadata()['po-header'] ?? null;

        self::assertIsArray($header1);
        self::assertIsArray($header2);
        self::assertSame($header1, $header2);
    }

    public function testRoundtripPreservesEscapeSequences(): void
    {
        $parser = new PoParser();
        $serializer = new PoSerializer();

        $bag1 = $parser->parse(new FileSource(self::FIXTURE_PATH), 'fr-FR');
        $bag2 = $parser->parse(
            new StringSource($serializer->serialize($bag1), extension: 'po'),
            'fr-FR',
        );

        $tricky = 'Quote: "hello"' . "\t" . 'and a backslash: \\' . "\n" . 'then a newline';
        self::assertTrue($bag1->has($tricky));
        self::assertTrue($bag2->has($tricky));
        self::assertSame($bag1->get($tricky)->content(), $bag2->get($tricky)->content());
    }

    public function testRoundtripPreservesPluralForms(): void
    {
        $parser = new PoParser();
        $serializer = new PoSerializer();

        $bag1 = $parser->parse(new FileSource(self::FIXTURE_PATH), 'fr-FR');
        $bag2 = $parser->parse(
            new StringSource($serializer->serialize($bag1), extension: 'po'),
            'fr-FR',
        );

        $plural1 = $bag1->get('One apple');
        $plural2 = $bag2->get('One apple');

        self::assertSame($plural1->pluralForms(), $plural2->pluralForms());
        self::assertSame(
            $plural1->metadata()['msgid_plural'] ?? null,
            $plural2->metadata()['msgid_plural'] ?? null,
        );
    }

    public function testRoundtripPreservesContextDisambiguation(): void
    {
        $parser = new PoParser();
        $serializer = new PoSerializer();

        $bag1 = $parser->parse(new FileSource(self::FIXTURE_PATH), 'fr-FR');
        $bag2 = $parser->parse(
            new StringSource($serializer->serialize($bag1), extension: 'po'),
            'fr-FR',
        );

        self::assertSame(
            $bag1->get('Post', context: 'noun')->content(),
            $bag2->get('Post', context: 'noun')->content(),
        );
        self::assertSame(
            $bag1->get('Post', context: 'verb')->content(),
            $bag2->get('Post', context: 'verb')->content(),
        );
    }

    private function assertEntriesEquivalent(
        TranslationEntryInterface $expected,
        TranslationEntryInterface $actual,
    ): void {
        self::assertSame($expected->id(), $actual->id());
        self::assertSame($expected->domain(), $actual->domain());
        self::assertSame($expected->context(), $actual->context());
        self::assertSame($expected->content(), $actual->content());
        self::assertSame($expected->pluralForms(), $actual->pluralForms());

        $expectedMeta = $expected->metadata();
        $actualMeta = $actual->metadata();
        foreach (['comments', 'extracted', 'references', 'flags', 'msgid_plural'] as $key) {
            self::assertSame(
                $expectedMeta[$key] ?? null,
                $actualMeta[$key] ?? null,
                "Metadata key '$key' differs after round-trip",
            );
        }
    }
}
