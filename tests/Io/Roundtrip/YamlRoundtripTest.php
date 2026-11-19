<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Roundtrip;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Io\Parser\YamlParser;
use G\Translator\Io\Serializer\YamlSerializer;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(YamlParser::class)]
#[CoversClass(YamlSerializer::class)]
final class YamlRoundtripTest extends TestCase
{
    public function testFlatMapRoundtrip(): void
    {
        $yaml = <<<'YAML'
            hello: Hello
            bye: Goodbye
            YAML;

        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');

        self::assertCount(2, $bag);
        self::assertSame('Hello', $bag->get('hello')->content());

        $serialized = (new YamlSerializer())->serialize($bag);
        $reparsed = (new YamlParser())->parse(new StringSource($serialized), 'en');

        self::assertSame('Hello', $reparsed->get('hello')->content());
        self::assertSame('Goodbye', $reparsed->get('bye')->content());
    }

    public function testNestedMapsFlattenedWithDots(): void
    {
        $yaml = <<<'YAML'
            app:
              title: Title
              subtitle: Subtitle
              nested:
                deep: Deep
            YAML;

        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');

        self::assertSame('Title', $bag->get('app.title')->content());
        self::assertSame('Subtitle', $bag->get('app.subtitle')->content());
        self::assertSame('Deep', $bag->get('app.nested.deep')->content());
    }

    public function testSingleQuotedString(): void
    {
        $yaml = "hello: 'Bonjour, l''ami'";
        $bag = (new YamlParser())->parse(new StringSource($yaml), 'fr-FR');

        self::assertSame("Bonjour, l'ami", $bag->get('hello')->content());
    }

    public function testDoubleQuotedStringWithEscapes(): void
    {
        $yaml = 'hello: "Line 1\nLine 2\tTab"';
        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');

        self::assertSame("Line 1\nLine 2\tTab", $bag->get('hello')->content());
    }

    public function testCommentsAreIgnored(): void
    {
        $yaml = <<<'YAML'
            # full-line comment
            hello: Hello # trailing comment
            bye: Goodbye
            YAML;

        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');
        self::assertCount(2, $bag);
        self::assertSame('Hello', $bag->get('hello')->content());
    }

    public function testDocumentStartMarkerIsTolerated(): void
    {
        $yaml = "---\nhello: Hello";
        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');
        self::assertSame('Hello', $bag->get('hello')->content());
    }

    public function testTabIndentationIsRejected(): void
    {
        $yaml = "app:\n\ttitle: Title";
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource($yaml), 'en');
    }

    public function testMultilineScalarIsRejected(): void
    {
        $yaml = "hello: |\n  multi-line\n  banned";
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource($yaml), 'en');
    }

    public function testFlowStyleIsRejected(): void
    {
        $yaml = "data: {key: value}";
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource($yaml), 'en');
    }

    public function testListsAreRejected(): void
    {
        $yaml = "items:\n  - one\n  - two";
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource($yaml), 'en');
    }

    public function testAnchorsAreRejected(): void
    {
        $yaml = "hello: &ref Hello\nbye: *ref";
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource($yaml), 'en');
    }

    public function testSerializerAlwaysDoubleQuotes(): void
    {
        $bag = new TranslationBag('en');
        // `yes` would be implicitly typed as a boolean in YAML — double
        // quoting protects against that.
        $bag->add(new TranslationEntry(id: 'flag', content: 'yes'));

        $out = (new YamlSerializer())->serialize($bag);
        self::assertStringContainsString('flag: "yes"', $out);
    }

    public function testSerializerEscapesSpecialCharacters(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'k', content: "line\nwith\ttab and \"quote\""));

        $out = (new YamlSerializer())->serialize($bag);
        self::assertStringContainsString('"line\\nwith\\ttab and \\"quote\\""', $out);
    }

    public function testEmptyBagSerializesToEmptyString(): void
    {
        self::assertSame('', (new YamlSerializer())->serialize(new TranslationBag('en')));
    }

    public function testEmptyYamlReturnsEmptyBag(): void
    {
        $bag = (new YamlParser())->parse(new StringSource(''), 'en');
        self::assertCount(0, $bag);
    }

    public function testNestedMapWithSiblingKey(): void
    {
        $yaml = <<<'YAML'
            app:
              title: Title
            bye: Goodbye
            YAML;

        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');
        self::assertSame('Title', $bag->get('app.title')->content());
        self::assertSame('Goodbye', $bag->get('bye')->content());
    }

    public function testParserFormat(): void
    {
        self::assertSame(\G\Translator\Enum\Format::YAML, (new YamlParser())->format());
    }

    public function testSerializerFormat(): void
    {
        self::assertSame(\G\Translator\Enum\Format::YAML, (new YamlSerializer())->format());
    }

    public function testBomPrefixIsTolerated(): void
    {
        $yaml = "\xEF\xBB\xBF" . "hello: Hello";
        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');
        self::assertSame('Hello', $bag->get('hello')->content());
    }

    public function testCrlfLineEndingsAreHandled(): void
    {
        $yaml = "hello: Hello\r\nbye: Goodbye";
        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');
        self::assertCount(2, $bag);
        self::assertSame('Hello', $bag->get('hello')->content());
    }

    public function testUnexpectedIndentThrows(): void
    {
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource("  hello: Hello"), 'en');
    }

    public function testKeyWithEmptyValueBecomesEmptyEntry(): void
    {
        $bag = (new YamlParser())->parse(new StringSource("hello:"), 'en');
        self::assertSame('', $bag->get('hello')->content());
    }

    public function testFlowStyleInKeyPositionThrows(): void
    {
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource("{key}: value"), 'en');
    }

    public function testInvalidKeyFormatThrows(): void
    {
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource("not a valid key line"), 'en');
    }

    public function testMultilineScalarWithChevronIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource("hello: > folded"), 'en');
    }

    public function testTagsAreRejected(): void
    {
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource("hello: !!str value"), 'en');
    }

    public function testDoubleQuotedBackslashRoundtrips(): void
    {
        // YAML: hello: "\\" → inner = \\ → unescapes to \
        $bag = (new YamlParser())->parse(new StringSource('hello: "\\\\"'), 'en');
        self::assertSame('\\', $bag->get('hello')->content());
    }

    public function testDoubleQuotedEscapedQuoteRoundtrips(): void
    {
        // YAML: hello: "say \"hi\"" → inner = say \"hi\" → unescapes to say "hi"
        $bag = (new YamlParser())->parse(new StringSource('hello: "say \\"hi\\""'), 'en');
        self::assertSame('say "hi"', $bag->get('hello')->content());
    }

    public function testDoubleQuotedCarriageReturnRoundtrips(): void
    {
        // YAML: hello: "line\rCR" → \r escape → actual CR char
        $bag = (new YamlParser())->parse(new StringSource('hello: "line\\rCR"'), 'en');
        self::assertSame("line\rCR", $bag->get('hello')->content());
    }

    public function testDoubleQuotedUnknownEscapeIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource('hello: "val\\qhex"'), 'en');
    }

    public function testUnterminatedDoubleQuotedStringIsRejected(): void
    {
        // YAML: hello: "hello\" — the backslash escapes the closing quote
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource('hello: "hello\\"'), 'en');
    }

    public function testSerializerEscapesBackslash(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'k', content: 'a\\b'));

        $out = (new YamlSerializer())->serialize($bag);
        self::assertStringContainsString('k: "a\\\\b"', $out);
    }

    public function testSerializerEscapesCarriageReturn(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'k', content: "line\rCR"));

        $out = (new YamlSerializer())->serialize($bag);
        self::assertStringContainsString('k: "line\\rCR"', $out);
    }

    public function testHashInsideQuotedValueIsNotAComment(): void
    {
        $yaml = "a: 'Item #1' # comment\nb: \"Tag #2\"\nc: Price #3";
        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');

        self::assertSame('Item #1', $bag->get('a')->content());
        self::assertSame('Tag #2', $bag->get('b')->content());
        self::assertSame('Price', $bag->get('c')->content());
    }

    public function testCommentAfterKeyOfNestedMap(): void
    {
        $yaml = "app: # section\n  title: Title";
        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');

        self::assertSame('Title', $bag->get('app.title')->content());
    }

    public function testGarbageAfterQuotedValueIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource("a: 'one' two"), 'en');
    }

    public function testQuotedKeys(): void
    {
        $yaml = "'Hello world': Bonjour\n\"Bye: now\": Salut\n'it''s': C'est";
        $bag = (new YamlParser())->parse(new StringSource($yaml), 'fr');

        self::assertSame('Bonjour', $bag->get('Hello world')->content());
        self::assertSame('Salut', $bag->get('Bye: now')->content());
        self::assertSame("C'est", $bag->get("it's")->content());
    }

    public function testDuplicateKeyIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource("a: one\na: two"), 'en');
    }

    public function testDocumentStartMarkerAfterAComment(): void
    {
        $bag = (new YamlParser())->parse(new StringSource("# header\n---\nhello: Hello"), 'en');
        self::assertSame('Hello', $bag->get('hello')->content());
    }

    public function testUnicodeAndHexEscapes(): void
    {
        $yaml = 'a: "caf\\u00e9 \\xE9 \\U0001F600 \\/ \\0end"';
        $bag = (new YamlParser())->parse(new StringSource($yaml), 'en');

        self::assertSame("café é \u{1F600} / \0end", $bag->get('a')->content());
    }

    public function testSurrogateEscapeIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new YamlParser())->parse(new StringSource('a: "\\uD800"'), 'en');
    }

    public function testSerializerQuotesKeysThatAreNotPlain(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'app.title', content: 'Title'));
        $bag->add(new TranslationEntry(id: 'Hello world: # "x"', content: 'Bonjour'));
        $bag->add(new TranslationEntry(id: 'yes', content: 'Oui'));
        $bag->add(new TranslationEntry(id: '42', content: 'Quarante-deux'));

        $out = (new YamlSerializer())->serialize($bag);
        self::assertStringContainsString("app.title: \"Title\"\n", $out);
        self::assertStringContainsString('"Hello world: # \\"x\\"": "Bonjour"', $out);
        self::assertStringContainsString('"yes": "Oui"', $out);

        $reparsed = (new YamlParser())->parse(new StringSource($out), 'en');
        self::assertSame('Bonjour', $reparsed->get('Hello world: # "x"')->content());
        self::assertSame('Oui', $reparsed->get('yes')->content());
        self::assertSame('Quarante-deux', $reparsed->get('42')->content());
    }

    public function testControlCharactersRoundtrip(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'k', content: "bell\x07 esc\x1B del\x7F"));

        $out = (new YamlSerializer())->serialize($bag);
        self::assertStringContainsString('k: "bell\\x07 esc\\x1B del\\x7F"', $out);

        $reparsed = (new YamlParser())->parse(new StringSource($out), 'en');
        self::assertSame("bell\x07 esc\x1B del\x7F", $reparsed->get('k')->content());
    }
}
