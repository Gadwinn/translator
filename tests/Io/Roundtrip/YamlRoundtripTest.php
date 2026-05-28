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

    public function testDoubleQuotedUnknownEscapeIsPreserved(): void
    {
        // \x is not a known escape — kept verbatim as \x
        $bag = (new YamlParser())->parse(new StringSource('hello: "val\\xhex"'), 'en');
        self::assertSame('val\\xhex', $bag->get('hello')->content());
    }

    public function testDoubleQuotedTrailingBackslashIsLiteral(): void
    {
        // YAML: hello: "hello\" — inner = hello\ (backslash at end, no next char)
        $bag = (new YamlParser())->parse(new StringSource('hello: "hello\\"'), 'en');
        self::assertSame('hello\\', $bag->get('hello')->content());
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

}
