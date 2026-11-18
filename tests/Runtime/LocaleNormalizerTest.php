<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime;

use G\Translator\Exception\Runtime\InvalidLocaleException;
use G\Translator\Runtime\LocaleNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocaleNormalizer::class)]
final class LocaleNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizeProvider(): iterable
    {
        yield 'language-only lowercase' => ['fr', 'fr'];
        yield 'language uppercased input' => ['FR', 'fr'];
        yield 'hyphen language-region' => ['pl-PL', 'pl-PL'];
        yield 'underscore language-region' => ['pl_PL', 'pl-PL'];
        yield 'mixed case language-region' => ['PL-pl', 'pl-PL'];
        yield 'language-script-region' => ['zh-Hant-TW', 'zh-Hant-TW'];
        yield 'language-script-region scrambled case' => ['ZH-hant-tw', 'zh-Hant-TW'];
        yield 'language with numeric region' => ['es-419', 'es-419'];
        yield 'iso 639-3 language' => ['fil', 'fil'];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, LocaleNormalizer::normalize($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['fr FR'];
        yield 'non-ascii' => ["fr\u{00E9}"];
        yield 'too many subtags' => ['fr-FR-FR-FR'];
        yield 'invalid language length' => ['frenchy'];
        yield 'unknown subtag shape' => ['fr-12'];
        yield 'single char subtag hits looksLikeRegion false' => ['fr-F'];
    }

    #[DataProvider('rejectProvider')]
    public function testRejects(string $input): void
    {
        $this->expectException(InvalidLocaleException::class);
        LocaleNormalizer::normalize($input);
    }

    public function testParentDropsRightmostSubtag(): void
    {
        self::assertSame('zh-Hant', LocaleNormalizer::parent('zh-Hant-TW'));
        self::assertSame('zh', LocaleNormalizer::parent('zh-Hant'));
        self::assertSame('fr', LocaleNormalizer::parent('fr-FR'));
    }

    public function testParentReturnsNullForLanguageOnly(): void
    {
        self::assertNull(LocaleNormalizer::parent('fr'));
    }
}
