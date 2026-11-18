<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Roundtrip;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Exception\Io\SerializerException;
use G\Translator\Io\Parser\AngularJsonParser;
use G\Translator\Io\Serializer\AngularJsonSerializer;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AngularJsonParser::class)]
#[CoversClass(AngularJsonSerializer::class)]
final class AngularJsonRoundtripTest extends TestCase
{
    public function testNestedRoundtripPreservesStructure(): void
    {
        $source = new StringSource('{"app":{"title":"Title","sub":{"label":"Label"}}}');
        $bag = (new AngularJsonParser())->parse($source, 'en');

        self::assertCount(2, $bag);
        self::assertSame('Title', $bag->get('app.title')->content());
        self::assertSame('Label', $bag->get('app.sub.label')->content());

        $serialized = (new AngularJsonSerializer())->serialize($bag);
        self::assertStringContainsString('"app"', $serialized);
        self::assertStringContainsString('"sub"', $serialized);
        self::assertStringContainsString('"label"', $serialized);

        $reparsed = (new AngularJsonParser())->parse(new StringSource($serialized), 'en');
        self::assertSame($bag->get('app.title')->content(), $reparsed->get('app.title')->content());
        self::assertSame($bag->get('app.sub.label')->content(), $reparsed->get('app.sub.label')->content());
    }

    public function testFlatInputUnflatteneOnSerialize(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'a.b.c', content: 'leaf'));

        $serialized = (new AngularJsonSerializer())->serialize($bag);

        // Must un-flatten the dotted id into nested JSON.
        $decoded = json_decode($serialized, true);
        self::assertSame('leaf', $decoded['a']['b']['c'] ?? null);
    }

    public function testConflictBetweenLeafAndContainerThrows(): void
    {
        $bag = new TranslationBag('en');
        // `app` would be a leaf "X", but `app.title` requires `app` to be a container.
        $bag->add(new TranslationEntry(id: 'app', content: 'X'));
        $bag->add(new TranslationEntry(id: 'app.title', content: 'Title'));

        $this->expectException(SerializerException::class);
        (new AngularJsonSerializer())->serialize($bag);
    }

    public function testEmptyBagSerializesToObjectBraces(): void
    {
        self::assertSame('{}', (new AngularJsonSerializer())->serialize(new TranslationBag('en')));
    }

    public function testParserFormat(): void
    {
        self::assertSame(\G\Translator\Enum\Format::ANGULAR_JSON, (new AngularJsonParser())->format());
    }

    public function testSerializerFormat(): void
    {
        self::assertSame(\G\Translator\Enum\Format::ANGULAR_JSON, (new AngularJsonSerializer())->format());
    }

    public function testEmptySourceReturnsEmptyBag(): void
    {
        $bag = (new AngularJsonParser())->parse(new StringSource(''), 'en');
        self::assertCount(0, $bag);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\G\Translator\Exception\Io\ParserException::class);
        (new AngularJsonParser())->parse(new StringSource('{not valid'), 'en');
    }

    public function testTopLevelNonObjectThrows(): void
    {
        $this->expectException(\G\Translator\Exception\Io\ParserException::class);
        (new AngularJsonParser())->parse(new StringSource('"just a string"'), 'en');
    }

    public function testEmptyKeyThrows(): void
    {
        $this->expectException(\G\Translator\Exception\Io\ParserException::class);
        (new AngularJsonParser())->parse(new StringSource('{"": "value"}'), 'en');
    }

    public function testNonStringLeafThrows(): void
    {
        $this->expectException(\G\Translator\Exception\Io\ParserException::class);
        (new AngularJsonParser())->parse(new StringSource('{"key": 42}'), 'en');
    }

    public function testLeafConflictsWithExistingContainerThrows(): void
    {
        $bag = new TranslationBag('en');
        // Create a container at 'app' first via 'app.title', then try to place a leaf at 'app'.
        $bag->add(new TranslationEntry(id: 'app.title', content: 'Title'));
        $bag->add(new TranslationEntry(id: 'app', content: 'X'));

        $this->expectException(SerializerException::class);
        (new AngularJsonSerializer())->serialize($bag);
    }

    public function testSerializerWrapsJsonExceptionAsSerializerException(): void
    {
        $bag = new TranslationBag('en');
        // Invalid UTF-8 byte sequence makes json_encode throw under JSON_THROW_ON_ERROR.
        $bag->add(new TranslationEntry(id: 'bad', content: "\xC3\x28"));

        $this->expectException(SerializerException::class);
        $this->expectExceptionMessage('Failed to encode translation bag as Angular JSON');
        (new AngularJsonSerializer())->serialize($bag);
    }
}
