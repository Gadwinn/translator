<?php

declare(strict_types=1);

namespace G\Translator\Tests\Engine;

use G\Translator\Engine\StackedSource;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StackedSource::class)]
final class StackedSourceTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $source = new StringSource('{}', extension: 'json');
        $stacked = new StackedSource($source, priority: 5);

        self::assertSame($source, $stacked->source);
        self::assertSame(5, $stacked->priority);
    }

    public function testDefaultPriorityIsZero(): void
    {
        $source = new StringSource('{}', extension: 'json');
        $stacked = new StackedSource($source);

        self::assertSame(0, $stacked->priority);
    }

    public function testNegativePriorityIsAccepted(): void
    {
        $source = new StringSource('{}', extension: 'json');
        $stacked = new StackedSource($source, priority: -10);

        self::assertSame(-10, $stacked->priority);
    }
}
