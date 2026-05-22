<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Source;

use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StringSource::class)]
final class StringSourceTest extends TestCase
{
    public function testReadReturnsConstructorContent(): void
    {
        $source = new StringSource('hello world');
        self::assertSame('hello world', $source->read());
    }

    public function testEmptyContentIsAccepted(): void
    {
        $source = new StringSource('');
        self::assertSame('', $source->read());
    }

    public function testDefaultIdentifierIsStableForSameContent(): void
    {
        $a = new StringSource('payload');
        $b = new StringSource('payload');
        self::assertSame($a->identifier(), $b->identifier());
        self::assertStringStartsWith('string://', $a->identifier());
    }

    public function testDefaultIdentifierDiffersForDifferentContent(): void
    {
        $a = new StringSource('payload');
        $b = new StringSource('different');
        self::assertNotSame($a->identifier(), $b->identifier());
    }

    public function testExplicitIdentifierOverridesDefault(): void
    {
        $source = new StringSource('payload', identifier: 'inline-snippet');
        self::assertSame('inline-snippet', $source->identifier());
    }

    public function testExtensionIsLowerCase(): void
    {
        $source = new StringSource('payload', extension: 'PO');
        self::assertSame('po', $source->extension());
    }

    public function testExtensionIsNullByDefault(): void
    {
        $source = new StringSource('payload');
        self::assertNull($source->extension());
    }
}
