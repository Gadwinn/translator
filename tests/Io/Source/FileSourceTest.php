<?php

declare(strict_types=1);

namespace G\Translator\Tests\Io\Source;

use G\Translator\Exception\Io\SourceNotFoundException;
use G\Translator\Io\Source\FileSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileSource::class)]
final class FileSourceTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'translator-filesource-');
        self::assertNotFalse($tmp, 'tempnam failed');
        $this->tempPath = $tmp . '.po';
        rename($tmp, $this->tempPath);
        file_put_contents($this->tempPath, "msgid \"hi\"\nmsgstr \"bonjour\"\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempPath)) {
            unlink($this->tempPath);
        }
    }

    public function testEmptyPathIsRejected(): void
    {
        $this->expectException(SourceNotFoundException::class);
        new FileSource('');
    }

    public function testIdentifierReturnsPath(): void
    {
        $source = new FileSource($this->tempPath);
        self::assertSame($this->tempPath, $source->identifier());
    }

    public function testExtensionIsLowerCase(): void
    {
        $source = new FileSource('/some/dir/Translations.PO');
        self::assertSame('po', $source->extension());
    }

    public function testExtensionIsNullWhenAbsent(): void
    {
        $source = new FileSource('/some/dir/Makefile');
        self::assertNull($source->extension());
    }

    public function testReadReturnsContent(): void
    {
        $source = new FileSource($this->tempPath);
        $content = $source->read();
        self::assertStringContainsString('msgid "hi"', $content);
    }

    public function testReadThrowsWhenFileMissing(): void
    {
        $source = new FileSource($this->tempPath . '.does-not-exist');
        $this->expectException(SourceNotFoundException::class);
        $source->read();
    }
}
