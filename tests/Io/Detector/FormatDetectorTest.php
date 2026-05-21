<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Detector;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Enum\Format;
use G\Translator\Exception\Io\UnsupportedFormatException;
use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Parser\ParserInterface;
use G\Translator\Io\Serializer\SerializerInterface;
use G\Translator\Io\Source\SourceInterface;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormatDetector::class)]
final class FormatDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{non-empty-string, Format}>
     */
    public static function extensionProvider(): iterable
    {
        yield 'po'    => ['po', Format::PO];
        yield 'pot'   => ['pot', Format::PO];
        yield 'xlf'   => ['xlf', Format::XLIFF];
        yield 'xliff' => ['xliff', Format::XLIFF];
        yield 'yaml'  => ['yaml', Format::YAML];
        yield 'yml'   => ['yml', Format::YAML];
        yield 'php'   => ['php', Format::PHP];
    }

    #[DataProvider('extensionProvider')]
    public function testExtensionDetectionForUnambiguousFormats(string $extension, Format $expected): void
    {
        $detector = new FormatDetector();
        $source = new StringSource('payload', extension: $extension);

        self::assertSame($expected, $detector->detectFormat($source));
    }

    public function testJsonExtensionWithFlatStringDictionaryIsAngular(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource(
            '{"HELLO": "Hello", "WELCOME": "Welcome"}',
            extension: 'json',
        );

        self::assertSame(Format::ANGULAR_JSON, $detector->detectFormat($source));
    }

    public function testJsonExtensionWithNestedStringNamespaceIsAngular(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource(
            '{"app": {"title": "T", "subtitle": "S"}, "errors": {"required": "R"}}',
            extension: 'json',
        );

        self::assertSame(Format::ANGULAR_JSON, $detector->detectFormat($source));
    }

    public function testJsonExtensionWithNonStringLeafFallsBackToJson(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource(
            '{"items": [{"id": 1, "label": "x"}]}',
            extension: 'json',
        );

        self::assertSame(Format::JSON, $detector->detectFormat($source));
    }

    public function testEmptyJsonObjectStaysJson(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource('{}', extension: 'json');

        self::assertSame(Format::JSON, $detector->detectFormat($source));
    }

    public function testInvalidJsonStaysJson(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource('not-actually-json', extension: 'json');

        self::assertSame(Format::JSON, $detector->detectFormat($source));
    }

    public function testContentFallbackDetectsPoByMsgid(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource("msgid \"hello\"\nmsgstr \"bonjour\"\n");

        self::assertSame(Format::PO, $detector->detectFormat($source));
    }

    public function testContentFallbackDetectsXliffByXmlPrologue(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource('<?xml version="1.0"?><xliff/>');

        self::assertSame(Format::XLIFF, $detector->detectFormat($source));
    }

    public function testContentFallbackDetectsJsonByOpeningBrace(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource('{"hello": "Hello"}');

        // No extension, content starts with `{` and is a flat string dict.
        self::assertSame(Format::ANGULAR_JSON, $detector->detectFormat($source));
    }

    public function testContentFallbackDetectsPhpByOpeningTag(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource('<?php return ["hi" => "bonjour"];');

        self::assertSame(Format::PHP, $detector->detectFormat($source));
    }

    public function testContentFallbackDetectsYamlByKeyColon(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource("hello: bonjour\n");

        self::assertSame(Format::YAML, $detector->detectFormat($source));
    }

    public function testContentFallbackRejectsEmptySource(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource('');

        $this->expectException(UnsupportedFormatException::class);
        $detector->detectFormat($source);
    }

    public function testContentFallbackRejectsUnknownPayload(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource('arbitrary binary garbage');

        $this->expectException(UnsupportedFormatException::class);
        $detector->detectFormat($source);
    }

    public function testParserForReturnsRegisteredParser(): void
    {
        $detector = new FormatDetector();
        $parser = $this->stubParser(Format::PO);
        $detector->registerParser($parser);

        $source = new StringSource('msgid "x"', extension: 'po');
        self::assertSame($parser, $detector->parserFor($source));
    }

    public function testParserForThrowsWhenNoParserRegistered(): void
    {
        $detector = new FormatDetector();
        $source = new StringSource('msgid "x"', extension: 'po');

        $this->expectException(UnsupportedFormatException::class);
        $detector->parserFor($source);
    }

    public function testSerializerForReturnsRegisteredSerializer(): void
    {
        $detector = new FormatDetector();
        $serializer = $this->stubSerializer(Format::JSON);
        $detector->registerSerializer($serializer);

        self::assertSame($serializer, $detector->serializerFor(Format::JSON));
    }

    public function testSerializerForThrowsWhenNoSerializerRegistered(): void
    {
        $detector = new FormatDetector();

        $this->expectException(UnsupportedFormatException::class);
        $detector->serializerFor(Format::JSON);
    }

    public function testRegisterParserReplacesExisting(): void
    {
        $detector = new FormatDetector();
        $first = $this->stubParser(Format::PO);
        $second = $this->stubParser(Format::PO);

        $detector->registerParser($first);
        $detector->registerParser($second);

        $source = new StringSource('msgid "x"', extension: 'po');
        self::assertSame($second, $detector->parserFor($source));
    }

    private function stubParser(Format $format): ParserInterface
    {
        return new class ($format) implements ParserInterface {
            public function __construct(private readonly Format $format)
            {
            }

            public function parse(
                SourceInterface $source,
                string $locale,
                string $domain = 'messages',
            ): TranslationBagInterface {
                return new TranslationBag($locale);
            }

            public function format(): Format
            {
                return $this->format;
            }
        };
    }

    private function stubSerializer(Format $format): SerializerInterface
    {
        return new class ($format) implements SerializerInterface {
            public function __construct(private readonly Format $format)
            {
            }

            public function serialize(
                TranslationBagInterface $bag,
                ?string $domain = null,
            ): string {
                return '';
            }

            public function format(): Format
            {
                return $this->format;
            }
        };
    }
}
