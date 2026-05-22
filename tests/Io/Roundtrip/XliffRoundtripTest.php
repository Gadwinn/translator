<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Roundtrip;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Io\Parser\XliffParser;
use G\Translator\Io\Serializer\XliffSerializer;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XliffParser::class)]
#[CoversClass(XliffSerializer::class)]
final class XliffRoundtripTest extends TestCase
{
    private const string XLIFF_12 = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">
          <file source-language="en" target-language="fr-FR" datatype="plaintext" original="messages">
            <body>
              <trans-unit id="hello">
                <source>Hello</source>
                <target>Bonjour</target>
                <note>Greeting on dashboard</note>
              </trans-unit>
              <trans-unit id="bye">
                <source>Goodbye</source>
                <target>Au revoir</target>
              </trans-unit>
            </body>
          </file>
        </xliff>
        XML;

    private const string XLIFF_20 = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <xliff version="2.0" xmlns="urn:oasis:names:tc:xliff:document:2.0" srcLang="en" trgLang="fr-FR">
          <file id="messages">
            <unit id="hello">
              <notes>
                <note>Greeting on dashboard</note>
              </notes>
              <segment>
                <source>Hello</source>
                <target>Bonjour</target>
              </segment>
            </unit>
            <unit id="bye">
              <segment>
                <source>Goodbye</source>
                <target>Au revoir</target>
              </segment>
            </unit>
          </file>
        </xliff>
        XML;

    public function testParse12(): void
    {
        $bag = (new XliffParser())->parse(new StringSource(self::XLIFF_12), 'fr-FR');

        self::assertCount(2, $bag);
        self::assertSame('Bonjour', $bag->get('hello')->content());
        self::assertSame('Au revoir', $bag->get('bye')->content());
        self::assertSame('1.2', $bag->get('hello')->metadata()['xliff-version']);
        self::assertSame(['Greeting on dashboard'], $bag->get('hello')->metadata()['notes']);
    }

    public function testParse20(): void
    {
        $bag = (new XliffParser())->parse(new StringSource(self::XLIFF_20), 'fr-FR');

        self::assertCount(2, $bag);
        self::assertSame('Bonjour', $bag->get('hello')->content());
        self::assertSame('2.0', $bag->get('hello')->metadata()['xliff-version']);
        self::assertSame(['Greeting on dashboard'], $bag->get('hello')->metadata()['notes']);
    }

    public function testRoundtrip12PreservesVersionAndNotes(): void
    {
        $bag1 = (new XliffParser())->parse(new StringSource(self::XLIFF_12), 'fr-FR');
        $serialized = (new XliffSerializer())->serialize($bag1);

        self::assertStringContainsString('version="1.2"', $serialized);
        self::assertStringContainsString('urn:oasis:names:tc:xliff:document:1.2', $serialized);

        $bag2 = (new XliffParser())->parse(new StringSource($serialized), 'fr-FR');
        self::assertSame($bag1->get('hello')->content(), $bag2->get('hello')->content());
        self::assertSame('1.2', $bag2->get('hello')->metadata()['xliff-version']);
        self::assertSame(['Greeting on dashboard'], $bag2->get('hello')->metadata()['notes']);
    }

    public function testRoundtrip20PreservesVersionAndNotes(): void
    {
        $bag1 = (new XliffParser())->parse(new StringSource(self::XLIFF_20), 'fr-FR');
        $serialized = (new XliffSerializer())->serialize($bag1);

        self::assertStringContainsString('version="2.0"', $serialized);
        self::assertStringContainsString('urn:oasis:names:tc:xliff:document:2.0', $serialized);

        $bag2 = (new XliffParser())->parse(new StringSource($serialized), 'fr-FR');
        self::assertSame($bag1->get('hello')->content(), $bag2->get('hello')->content());
        self::assertSame('2.0', $bag2->get('hello')->metadata()['xliff-version']);
    }

    public function testEmptyBagSerializesAsValidXliffDocument(): void
    {
        $bag = new TranslationBag('fr-FR');
        $out = (new XliffSerializer())->serialize($bag);

        self::assertStringContainsString('<?xml', $out);
        self::assertStringContainsString('version="1.2"', $out, 'Default version is 1.2');
        // Output is parseable round-trip — even empty.
        $reparsed = (new XliffParser())->parse(new StringSource($out), 'fr-FR');
        self::assertCount(0, $reparsed);
    }

    public function testInvalidXmlIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new XliffParser())->parse(new StringSource('<not closed'), 'fr-FR');
    }

    public function testWrongRootElementIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new XliffParser())->parse(
            new StringSource('<?xml version="1.0"?><not-xliff/>'),
            'fr-FR',
        );
    }

    public function testUnsupportedVersionIsRejected(): void
    {
        $this->expectException(ParserException::class);
        (new XliffParser())->parse(
            new StringSource('<?xml version="1.0"?><xliff version="3.0"/>'),
            'fr-FR',
        );
    }

    public function testSerializerDefaultsToVersion12WhenNoMetadataPresent(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'hello', content: 'Hello'));

        $out = (new XliffSerializer())->serialize($bag);
        self::assertStringContainsString('version="1.2"', $out);
        self::assertStringContainsString('<trans-unit id="hello">', $out);
        self::assertStringContainsString('<source>hello</source>', $out);
        self::assertStringContainsString('<target>Hello</target>', $out);
    }
}
