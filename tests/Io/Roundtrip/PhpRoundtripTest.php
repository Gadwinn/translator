<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Roundtrip;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Io\Parser\PhpParser;
use G\Translator\Io\Serializer\PhpSerializer;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpParser::class)]
#[CoversClass(PhpSerializer::class)]
final class PhpRoundtripTest extends TestCase
{
    public function testFlatArrayRoundtrip(): void
    {
        $source = new StringSource("<?php return ['hello' => 'Hello', 'bye' => 'Goodbye'];");
        $bag = (new PhpParser())->parse($source, 'en');

        self::assertCount(2, $bag);
        self::assertSame('Hello', $bag->get('hello')->content());

        $serialized = (new PhpSerializer())->serialize($bag);
        self::assertStringContainsString("<?php", $serialized);
        self::assertStringContainsString('return', $serialized);

        $reparsed = (new PhpParser())->parse(new StringSource($serialized), 'en');
        self::assertSame($bag->count(), $reparsed->count());
        self::assertSame('Hello', $reparsed->get('hello')->content());
        self::assertSame('Goodbye', $reparsed->get('bye')->content());
    }

    public function testNestedArrayIsFlattened(): void
    {
        $source = new StringSource(<<<'PHP'
            <?php
            return [
                'app' => [
                    'title' => 'Title',
                    'subtitle' => 'Subtitle',
                ],
            ];
            PHP);

        $bag = (new PhpParser())->parse($source, 'en');

        self::assertSame('Title', $bag->get('app.title')->content());
        self::assertSame('Subtitle', $bag->get('app.subtitle')->content());
    }

    public function testStringWithQuotesAndBackslashRoundtrips(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(
            id: 'tricky',
            content: "Quote: 'apostrophe' and backslash: \\",
        ));

        $serialized = (new PhpSerializer())->serialize($bag);
        $reparsed = (new PhpParser())->parse(new StringSource($serialized), 'en');

        self::assertSame("Quote: 'apostrophe' and backslash: \\", $reparsed->get('tricky')->content());
    }

    public function testNonArrayReturnIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new PhpParser())->parse(new StringSource("<?php return 'not an array';"), 'en');
    }

    public function testSerializerOutputIncludesStrictTypesDeclaration(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'k', content: 'v'));

        $out = (new PhpSerializer())->serialize($bag);
        self::assertStringContainsString('declare(strict_types=1);', $out);
    }

    public function testEmptyContentRoundtrip(): void
    {
        $bag = (new PhpParser())->parse(new StringSource('<?php return [];'), 'en');
        self::assertCount(0, $bag);
    }

    public function testParserFormat(): void
    {
        self::assertSame(\G\Translator\Enum\Format::PHP, (new PhpParser())->format());
    }

    public function testSerializerFormat(): void
    {
        self::assertSame(\G\Translator\Enum\Format::PHP, (new PhpSerializer())->format());
    }

    public function testWhitespaceOnlyContentReturnsEmptyBag(): void
    {
        $bag = (new PhpParser())->parse(new StringSource('   '), 'en');
        self::assertCount(0, $bag);
    }

    public function testEvaluationErrorThrows(): void
    {
        $this->expectException(\G\Translator\Exception\Io\ParserException::class);
        (new PhpParser())->parse(new StringSource('<?php throw new \RuntimeException("oops");'), 'en');
    }

    public function testEmptyKeyThrows(): void
    {
        $this->expectException(\G\Translator\Exception\Io\ParserException::class);
        (new PhpParser())->parse(new StringSource('<?php return ["" => "value"];'), 'en');
    }

    public function testNonStringLeafThrows(): void
    {
        $this->expectException(\G\Translator\Exception\Io\ParserException::class);
        (new PhpParser())->parse(new StringSource('<?php return ["key" => 42];'), 'en');
    }
}
