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
}
