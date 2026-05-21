<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Parser;

use G\Translator\Enum\Format;
use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Io\Parser\PoParser;
use G\Translator\Io\Source\FileSource;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoParser::class)]
final class PoParserTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../../Fixtures/po/sample.po';

    public function testFormat(): void
    {
        self::assertSame(Format::PO, (new PoParser())->format());
    }

    public function testParseEmptySourceProducesEmptyBag(): void
    {
        $bag = (new PoParser())->parse(new StringSource(''), 'fr-FR');
        self::assertCount(0, $bag);
    }

    public function testParseSimpleSingularEntry(): void
    {
        $source = new StringSource(
            <<<'PO'
            msgid "Hello"
            msgstr "Bonjour"
            PO,
        );

        $bag = (new PoParser())->parse($source, 'fr-FR');

        self::assertCount(1, $bag);
        $entry = $bag->get('Hello');
        self::assertSame('Hello', $entry->id());
        self::assertSame('Bonjour', $entry->content());
        self::assertSame('messages', $entry->domain());
        self::assertNull($entry->context());
    }

    public function testParseMsgctxtDisambiguation(): void
    {
        $source = new StringSource(
            <<<'PO'
            msgctxt "noun"
            msgid "Post"
            msgstr "Article"

            msgctxt "verb"
            msgid "Post"
            msgstr "Publier"
            PO,
        );

        $bag = (new PoParser())->parse($source, 'en');

        self::assertCount(2, $bag);
        self::assertSame('Article', $bag->get('Post', context: 'noun')->content());
        self::assertSame('Publier', $bag->get('Post', context: 'verb')->content());
    }

    public function testParseMultilineString(): void
    {
        $source = new StringSource(
            <<<'PO'
            msgid ""
            "Welcome to the dashboard.\n"
            "You have new notifications."
            msgstr ""
            "Bienvenue.\n"
            "Notifications."
            PO,
        );

        $bag = (new PoParser())->parse($source, 'fr-FR');

        $entry = $bag->all()[0];
        self::assertSame("Welcome to the dashboard.\nYou have new notifications.", $entry->id());
        self::assertSame("Bienvenue.\nNotifications.", $entry->content());
    }

    public function testParseEscapeSequences(): void
    {
        $source = new StringSource(
            'msgid "Quote: \"x\"\tand backslash: \\\\\nnewline"' . "\n" .
            'msgstr "ok"',
        );

        $bag = (new PoParser())->parse($source, 'en');
        $entry = $bag->all()[0];
        self::assertSame("Quote: \"x\"\tand backslash: \\\nnewline", $entry->id());
    }

    public function testParsePluralEntry(): void
    {
        $source = new StringSource(
            <<<'PO'
            msgid ""
            msgstr ""
            "Plural-Forms: nplurals=2; plural=(n > 1);\n"

            msgid "One apple"
            msgid_plural "%d apples"
            msgstr[0] "Une pomme"
            msgstr[1] "%d pommes"
            PO,
        );

        $bag = (new PoParser())->parse($source, 'fr-FR');

        $entry = $bag->get('One apple');
        $forms = $entry->pluralForms();
        self::assertSame('Une pomme', $forms[PluralCategory::ONE->value]);
        self::assertSame('%d pommes', $forms[PluralCategory::OTHER->value]);
        self::assertSame('%d apples', $entry->metadata()['msgid_plural']);
    }

    public function testParseRussianPlural3Forms(): void
    {
        $source = new StringSource(
            <<<'PO'
            msgid ""
            msgstr ""
            "Plural-Forms: nplurals=3; plural=n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2;\n"

            msgid "%d item"
            msgid_plural "%d items"
            msgstr[0] "%d товар"
            msgstr[1] "%d товара"
            msgstr[2] "%d товаров"
            PO,
        );

        $bag = (new PoParser())->parse($source, 'ru');
        $entry = $bag->get('%d item');
        $forms = $entry->pluralForms();

        // nplurals=3 maps to [ONE, FEW, OTHER]
        self::assertSame('%d товар', $forms[PluralCategory::ONE->value]);
        self::assertSame('%d товара', $forms[PluralCategory::FEW->value]);
        self::assertSame('%d товаров', $forms[PluralCategory::OTHER->value]);
    }

    public function testParseHeaderAttachedToEveryEntry(): void
    {
        $source = new FileSource(self::FIXTURE_PATH);
        $bag = (new PoParser())->parse($source, 'fr-FR');

        foreach ($bag as $entry) {
            $header = $entry->metadata()['po-header'] ?? null;
            self::assertIsArray($header);
            self::assertSame('fr_FR', $header['Language'] ?? null);
            self::assertSame('nplurals=2; plural=(n > 1);', $header['Plural-Forms'] ?? null);
        }
    }

    public function testParseAttachesAllCommentMetadata(): void
    {
        $source = new FileSource(self::FIXTURE_PATH);
        $bag = (new PoParser())->parse($source, 'fr-FR');

        $entry = $bag->get('Hello %name%');
        self::assertSame(["Greeting shown on the dashboard."], $entry->metadata()['comments']);
        self::assertSame(
            ["Placeholder %name% is the logged-in user's display name."],
            $entry->metadata()['extracted'],
        );
        self::assertSame(['src/Dashboard.php:42'], $entry->metadata()['references']);
    }

    public function testParseSplitsMultipleReferencesOnTwoLines(): void
    {
        $source = new FileSource(self::FIXTURE_PATH);
        $bag = (new PoParser())->parse($source, 'fr-FR');

        $entry = $bag->get('Post', context: 'noun');
        self::assertSame(
            ['src/Dashboard.php:18', 'src/Dashboard.php:33'],
            $entry->metadata()['references'],
        );
        self::assertSame(['fuzzy', 'php-format'], $entry->metadata()['flags']);
    }

    public function testParseFixtureHasAllExpectedEntries(): void
    {
        $source = new FileSource(self::FIXTURE_PATH);
        $bag = (new PoParser())->parse($source, 'fr-FR');

        // Header is NOT an entry; every other id from the fixture is.
        // Fixture has 6 real entries: "Hello %name%", two "Post" (noun/verb),
        // the multi-line "Welcome…", the plural "One apple", and the
        // escape-sequence entry.
        self::assertCount(6, $bag);
        self::assertTrue($bag->has('Hello %name%'));
        self::assertTrue($bag->has('Post', context: 'noun'));
        self::assertTrue($bag->has('Post', context: 'verb'));
        self::assertTrue($bag->has('One apple'));
    }

    public function testParseUnrecognizedLineThrows(): void
    {
        $source = new StringSource("msgid \"x\"\nrandom garbage line");
        $this->expectException(ParserException::class);
        (new PoParser())->parse($source, 'en');
    }

    public function testParseStandaloneContinuationWithoutDirectiveThrows(): void
    {
        $source = new StringSource("\"orphan\"");
        $this->expectException(ParserException::class);
        (new PoParser())->parse($source, 'en');
    }

    public function testParseRespectsCustomDomain(): void
    {
        $source = new StringSource("msgid \"x\"\nmsgstr \"y\"");
        $bag = (new PoParser())->parse($source, 'en', 'admin');

        self::assertSame('admin', $bag->all()[0]->domain());
    }

    public function testParseStripsUtf8Bom(): void
    {
        $source = new StringSource("\xEF\xBB\xBFmsgid \"x\"\nmsgstr \"y\"");
        $bag = (new PoParser())->parse($source, 'en');

        self::assertSame('x', $bag->all()[0]->id());
    }
}
