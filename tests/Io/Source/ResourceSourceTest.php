<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Source;

use G\Translator\Exception\Io\SourceNotFoundException;
use G\Translator\Io\Source\ResourceSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceSource::class)]
final class ResourceSourceTest extends TestCase
{
    public function testReadFromMemoryStream(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertNotFalse($resource);
        fwrite($resource, 'streamed payload');
        rewind($resource);

        $source = new ResourceSource($resource);

        self::assertSame('streamed payload', $source->read());
    }

    public function testReadIsIdempotentAcrossCalls(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertNotFalse($resource);
        fwrite($resource, 'cached');
        rewind($resource);

        $source = new ResourceSource($resource);

        self::assertSame('cached', $source->read());
        // After the first read, the resource is released; subsequent
        // reads return the cached content rather than re-hitting it.
        self::assertSame('cached', $source->read());
    }

    public function testDefaultIdentifierReferencesResourceId(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertNotFalse($resource);
        $source = new ResourceSource($resource);

        self::assertStringStartsWith('resource://', $source->identifier());
    }

    public function testExplicitIdentifierOverridesDefault(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertNotFalse($resource);

        $source = new ResourceSource($resource, identifier: 'http://payload');
        self::assertSame('http://payload', $source->identifier());
    }

    public function testExplicitExtensionIsLowerCase(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertNotFalse($resource);

        $source = new ResourceSource($resource, extension: 'XLIFF');
        self::assertSame('xliff', $source->extension());
    }

    public function testNonStreamResourceIsRejected(): void
    {
        $this->expectException(SourceNotFoundException::class);
        new ResourceSource('not a resource');
    }

    public function testExtensionIsNullWhenNotProvided(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertNotFalse($resource);

        $source = new ResourceSource($resource);
        self::assertNull($source->extension());
    }
}
