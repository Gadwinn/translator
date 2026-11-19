<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Serializer;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Enum\Format;
use G\Translator\Enum\PluralCategory;
use G\Translator\Io\Serializer\PoSerializer;
use G\Translator\Io\Parser\PoParser;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoSerializer::class)]
final class PoSerializerTest extends TestCase
{
    public function testFormat(): void
    {
        self::assertSame(Format::PO, (new PoSerializer())->format());
    }

    public function testEmptyBagSerializesToEmptyString(): void
    {
        $bag = new TranslationBag('fr-FR');
        self::assertSame('', (new PoSerializer())->serialize($bag));
    }

    public function testSerializesSimpleEntry(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(id: 'Hello', content: 'Bonjour'));

        $output = (new PoSerializer())->serialize($bag);

        self::assertStringContainsString('msgid "Hello"', $output);
        self::assertStringContainsString('msgstr "Bonjour"', $output);
    }

    public function testSerializesEscapeSequences(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(
            id: "Quote: \"x\"\tand backslash: \\",
            content: 'ok',
        ));

        $output = (new PoSerializer())->serialize($bag);

        self::assertStringContainsString('msgid "Quote: \\"x\\"\\tand backslash: \\\\"', $output);
    }

    public function testSerializesMultilineContentAsMultilineLayout(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(
            id: "line 1\nline 2",
            content: "ligne 1\nligne 2",
        ));

        $output = (new PoSerializer())->serialize($bag);

        self::assertStringContainsString("msgid \"\"\n\"line 1\\n\"\n\"line 2\"", $output);
        self::assertStringContainsString("msgstr \"\"\n\"ligne 1\\n\"\n\"ligne 2\"", $output);
    }

    public function testSerializesContextBeforeMsgid(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'Post', content: 'Article', context: 'noun'));

        $output = (new PoSerializer())->serialize($bag);

        $msgctxtPos = strpos($output, 'msgctxt "noun"');
        $msgidPos = strpos($output, 'msgid "Post"');

        self::assertNotFalse($msgctxtPos);
        self::assertNotFalse($msgidPos);
        self::assertLessThan($msgidPos, $msgctxtPos);
    }

    public function testSerializesPluralEntry(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(
            id: 'One apple',
            content: 'Une pomme',
            pluralForms: [
                PluralCategory::ONE->value   => 'Une pomme',
                PluralCategory::OTHER->value => '%d pommes',
            ],
            metadata: ['msgid_plural' => '%d apples'],
        ));

        $output = (new PoSerializer())->serialize($bag);

        self::assertStringContainsString('msgid "One apple"', $output);
        self::assertStringContainsString('msgid_plural "%d apples"', $output);
        self::assertStringContainsString('msgstr[0] "Une pomme"', $output);
        self::assertStringContainsString('msgstr[1] "%d pommes"', $output);
    }

    public function testSerializesMetadataAsComments(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(
            id: 'Hello',
            content: 'Bonjour',
            metadata: [
                'comments'   => ['Translator comment'],
                'extracted'  => ['Extracted comment'],
                'references' => ['src/file.php:42', 'src/other.php:11'],
                'flags'      => ['fuzzy', 'php-format'],
            ],
        ));

        $output = (new PoSerializer())->serialize($bag);

        self::assertStringContainsString('# Translator comment', $output);
        self::assertStringContainsString('#. Extracted comment', $output);
        self::assertStringContainsString('#: src/file.php:42', $output);
        self::assertStringContainsString('#: src/other.php:11', $output);
        self::assertStringContainsString('#, fuzzy, php-format', $output);
    }

    public function testSerializesHeaderWhenPresent(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(
            id: 'Hello',
            content: 'Bonjour',
            metadata: [
                'po-header' => [
                    'Language' => 'fr-FR',
                    'Plural-Forms' => 'nplurals=2; plural=(n > 1);',
                ],
            ],
        ));

        $output = (new PoSerializer())->serialize($bag);

        self::assertStringContainsString("msgid \"\"\nmsgstr \"\"", $output);
        self::assertStringContainsString('Language: fr-FR', $output);
        self::assertStringContainsString('Plural-Forms: nplurals=2; plural=(n > 1);', $output);

        // Header must appear BEFORE the real entry.
        $headerPos = strpos($output, "msgid \"\"\nmsgstr \"\"");
        $entryPos = strpos($output, 'msgid "Hello"');
        self::assertNotFalse($headerPos);
        self::assertNotFalse($entryPos);
        self::assertLessThan($entryPos, $headerPos);
    }

    public function testSerializesCarriageReturnAsEscapedSequence(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'k', content: "line1\rline2"));

        $output = (new PoSerializer())->serialize($bag);

        self::assertStringContainsString('msgstr "line1\\rline2"', $output);
    }

    public function testPluralEntryWithoutMsgidPluralMetadataFallsBackToId(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(
            id: 'Item',
            content: 'Element',
            pluralForms: [
                PluralCategory::ONE->value   => 'Element',
                PluralCategory::OTHER->value => 'Elements',
            ],
            // No 'msgid_plural' metadata → serializer falls back to id().
        ));

        $output = (new PoSerializer())->serialize($bag);

        self::assertStringContainsString('msgid_plural "Item"', $output);
    }

    public function testHeaderWithoutPluralFormsUsesDefaultNplurals(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(
            id: 'One',
            content: 'One',
            pluralForms: [
                PluralCategory::ONE->value   => 'One',
                PluralCategory::OTHER->value => 'Many',
            ],
            metadata: [
                'msgid_plural' => 'Many',
                // 'po-header' present but without 'Plural-Forms' key.
                'po-header'    => ['Language' => 'en'],
            ],
        ));

        $output = (new PoSerializer())->serialize($bag);

        // DEFAULT_NPLURALS = 2 → only msgstr[0] and msgstr[1] emitted.
        self::assertStringContainsString('msgstr[0] "One"', $output);
        self::assertStringContainsString('msgstr[1] "Many"', $output);
        self::assertStringNotContainsString('msgstr[2]', $output);
    }

    public function testHeaderWithMalformedPluralFormsFallsBackToDefault(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(
            id: 'One',
            content: 'One',
            pluralForms: [
                PluralCategory::ONE->value   => 'One',
                PluralCategory::OTHER->value => 'Many',
            ],
            metadata: [
                'msgid_plural' => 'Many',
                // Plural-Forms header value with no nplurals declaration.
                'po-header'    => ['Plural-Forms' => 'garbage no nplurals here'],
            ],
        ));

        $output = (new PoSerializer())->serialize($bag);

        self::assertStringContainsString('msgstr[0] "One"', $output);
        self::assertStringContainsString('msgstr[1] "Many"', $output);
    }

    public function testHeaderWithOutOfTableNpluralsFallsBackToDefault(): void
    {
        // nplurals=0 is parseable by the regex but absent from the
        // GETTEXT_NPLURALS_TO_CATEGORIES table (which covers 1..6).
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(
            id: 'One',
            content: 'One',
            pluralForms: [
                PluralCategory::ONE->value   => 'One',
                PluralCategory::OTHER->value => 'Many',
            ],
            metadata: [
                'msgid_plural' => 'Many',
                'po-header'    => ['Plural-Forms' => 'nplurals=0; plural=0;'],
            ],
        ));

        $output = (new PoSerializer())->serialize($bag);

        // Falls back to DEFAULT_NPLURALS=2 → emits msgstr[0] and msgstr[1].
        self::assertStringContainsString('msgstr[0] "One"', $output);
        self::assertStringContainsString('msgstr[1] "Many"', $output);
    }

    public function testSerializesPolishPlural3Forms(): void
    {
        $bag = new TranslationBag('pl');
        $bag->add(new TranslationEntry(
            id: 'One item',
            content: 'Jeden element',
            pluralForms: [
                PluralCategory::ONE->value   => 'Jeden element',
                PluralCategory::FEW->value   => '%d elementy',
                PluralCategory::OTHER->value => '%d elementów',
            ],
            metadata: [
                'msgid_plural' => '%d items',
                'po-header' => ['Plural-Forms' => 'nplurals=3; plural=(...);'],
            ],
        ));

        $output = (new PoSerializer())->serialize($bag);

        self::assertStringContainsString('msgstr[0] "Jeden element"', $output);
        self::assertStringContainsString('msgstr[1] "%d elementy"', $output);
        self::assertStringContainsString('msgstr[2] "%d elementów"', $output);
    }

    public function testMultilineCommentsArePrefixedOnEveryLine(): void
    {
        $bag = new TranslationBag('fr');
        $bag->add(new TranslationEntry(
            id: 'hello',
            content: 'Bonjour',
            metadata: ['comments' => ["first line\nsecond line"], 'extracted' => ["a\r\nb"]],
        ));

        $out = (new PoSerializer())->serialize($bag);
        self::assertStringContainsString("# first line\n# second line\n#. a\n#. b\nmsgid", $out);

        $reparsed = (new PoParser())->parse(new StringSource($out), 'fr');
        self::assertSame('Bonjour', $reparsed->get('hello')->content());
    }
}
