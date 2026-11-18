<?php

declare(strict_types=1);

namespace G\Translator\Tests\Http\Enum;

use G\Translator\Http\Enum\HttpMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpMethod::class)]
final class HttpMethodTest extends TestCase
{
    public function testCasesHaveExpectedBackingValues(): void
    {
        self::assertSame('GET', HttpMethod::GET->value);
        self::assertSame('POST', HttpMethod::POST->value);
        self::assertSame('PUT', HttpMethod::PUT->value);
        self::assertSame('DELETE', HttpMethod::DELETE->value);
    }

    public function testPostHasBody(): void
    {
        self::assertTrue(HttpMethod::POST->hasBody());
    }

    public function testPutHasBody(): void
    {
        self::assertTrue(HttpMethod::PUT->hasBody());
    }

    public function testGetHasNoBody(): void
    {
        self::assertFalse(HttpMethod::GET->hasBody());
    }

    public function testDeleteHasNoBody(): void
    {
        self::assertFalse(HttpMethod::DELETE->hasBody());
    }
}
