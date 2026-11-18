<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Roundtrip;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Exception\Io\SerializerException;
use G\Translator\Io\Parser\JsonParser;
use G\Translator\Io\Serializer\JsonSerializer;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonParser::class)]
#[CoversClass(JsonSerializer::class)]
final class JsonRoundtripTest extends TestCase
{
    public function testFlatDictionaryRoundtrip(): void
    {
        $source = new StringSource('{"hello":"Hello","bye":"Goodbye"}');
        $bag = (new JsonParser())->parse($source, 'en');

        self::assertCount(2, $bag);
        self::assertSame('Hello', $bag->get('hello')->content());
        self::assertSame('Goodbye', $bag->get('bye')->content());

        $serialized = (new JsonSerializer())->serialize($bag);
        $reparsed = (new JsonParser())->parse(new StringSource($serialized), 'en');

        self::assertSame($bag->count(), $reparsed->count());
        self::assertSame($bag->get('hello')->content(), $reparsed->get('hello')->content());
    }

    public function testNestedDictionaryFlattensWithDots(): void
    {
        $source = new StringSource('{"app":{"title":"Title","subtitle":"Subtitle"}}');
        $bag = (new JsonParser())->parse($source, 'en');

        self::assertCount(2, $bag);
        self::assertSame('Title', $bag->get('app.title')->content());
        self::assertSame('Subtitle', $bag->get('app.subtitle')->content());
    }

    public function testEmptyJsonReturnsEmptyBag(): void
    {
        $bag = (new JsonParser())->parse(new StringSource(''), 'en');
        self::assertCount(0, $bag);
    }

    public function testEmptyBagSerializesToObjectBraces(): void
    {
        $bag = new TranslationBag('en');
        self::assertSame('{}', (new JsonSerializer())->serialize($bag));
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(ParserException::class);
        (new JsonParser())->parse(new StringSource('{not valid'), 'en');
    }

    public function testTopLevelArrayIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new JsonParser())->parse(new StringSource('"just a string"'), 'en');
    }

    public function testNonStringLeafIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new JsonParser())->parse(new StringSource('{"key": 42}'), 'en');
    }

    public function testSerializerProducesPrettyPrintedOutput(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'hello', content: 'Hello'));

        $out = (new JsonSerializer())->serialize($bag);

        self::assertStringContainsString("\n", $out, 'Pretty-printed JSON must contain newlines');
        self::assertStringContainsString('"hello"', $out);
        self::assertStringContainsString('"Hello"', $out);
    }

    public function testUnicodeIsPreservedAsIs(): void
    {
        $bag = new TranslationBag('fr-FR');
        $bag->add(new TranslationEntry(id: 'fr', content: 'Café à €5'));

        $out = (new JsonSerializer())->serialize($bag);
        self::assertStringContainsString('Café à €5', $out, 'UTF-8 must NOT be \uXXXX-escaped');
    }

    public function testParserFormat(): void
    {
        self::assertSame(\G\Translator\Enum\Format::JSON, (new JsonParser())->format());
    }

    public function testSerializerFormat(): void
    {
        self::assertSame(\G\Translator\Enum\Format::JSON, (new JsonSerializer())->format());
    }

    public function testEmptyKeyThrows(): void
    {
        $this->expectException(ParserException::class);
        (new JsonParser())->parse(new StringSource('{"": "value"}'), 'en');
    }

    public function testSerializerWrapsJsonExceptionAsSerializerException(): void
    {
        $bag = new TranslationBag('en');
        // Invalid UTF-8 byte sequence: json_encode throws JsonException
        // because JSON_THROW_ON_ERROR is set in ENCODE_FLAGS.
        $bag->add(new TranslationEntry(id: 'bad', content: "\xC3\x28"));

        $this->expectException(SerializerException::class);
        $this->expectExceptionMessage('Failed to encode translation bag as JSON');
        (new JsonSerializer())->serialize($bag);
    }
}
