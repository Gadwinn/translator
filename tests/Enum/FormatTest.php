<?php

declare(strict_types=1);

namespace G\Translator\Tests\Enum;

use G\Translator\Enum\Format;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Format::class)]
final class FormatTest extends TestCase
{
    public function testCasesHaveExpectedBackingValues(): void
    {
        self::assertSame('po', Format::PO->value);
        self::assertSame('xliff', Format::XLIFF->value);
        self::assertSame('yaml', Format::YAML->value);
        self::assertSame('json', Format::JSON->value);
        self::assertSame('php', Format::PHP->value);
        self::assertSame('angular_json', Format::ANGULAR_JSON->value);
    }

    /**
     * @return array<string, array{Format, non-empty-list<non-empty-string>}>
     */
    public static function extensionsProvider(): array
    {
        return [
            'PO'           => [Format::PO, ['po', 'pot']],
            'XLIFF'        => [Format::XLIFF, ['xlf', 'xliff']],
            'YAML'         => [Format::YAML, ['yaml', 'yml']],
            'JSON'         => [Format::JSON, ['json']],
            'PHP'          => [Format::PHP, ['php']],
            'ANGULAR_JSON' => [Format::ANGULAR_JSON, ['json']],
        ];
    }

    /**
     * @param non-empty-list<non-empty-string> $expected
     */
    #[DataProvider('extensionsProvider')]
    public function testExtensionsReturnsExpectedList(Format $format, array $expected): void
    {
        self::assertSame($expected, $format->extensions());
    }

    /**
     * @return array<string, array{string, Format|null}>
     */
    public static function tryFromExtensionProvider(): array
    {
        return [
            'po'      => ['po', Format::PO],
            'pot'     => ['pot', Format::PO],
            'xlf'     => ['xlf', Format::XLIFF],
            'xliff'   => ['xliff', Format::XLIFF],
            'yaml'    => ['yaml', Format::YAML],
            'yml'     => ['yml', Format::YAML],
            'json'    => ['json', Format::JSON],
            'php'     => ['php', Format::PHP],
            'unknown' => ['txt', null],
        ];
    }

    #[DataProvider('tryFromExtensionProvider')]
    public function testTryFromExtensionResolvesCorrectFormat(string $extension, ?Format $expected): void
    {
        self::assertSame($expected, Format::tryFromExtension($extension));
    }

    public function testTryFromExtensionIsNotCaseSensitive(): void
    {
        self::assertSame(Format::PO, Format::tryFromExtension('PO'));
        self::assertSame(Format::YAML, Format::tryFromExtension('YAML'));
    }

    public function testTryFromExtensionStripsLeadingDot(): void
    {
        self::assertSame(Format::PO, Format::tryFromExtension('.po'));
        self::assertSame(Format::JSON, Format::tryFromExtension('.json'));
    }
}
