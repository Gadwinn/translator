<?php

declare(strict_types=1);

namespace G\Translator\Tests\Http;

use G\Translator\Http\Enum\HttpMethod;
use G\Translator\Http\HttpRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpRequest::class)]
final class HttpRequestTest extends TestCase
{
    public function testConstructorSetsRequiredProperties(): void
    {
        $request = new HttpRequest(
            method: HttpMethod::GET,
            url: 'https://api.example.com/translate',
        );

        self::assertSame(HttpMethod::GET, $request->method);
        self::assertSame('https://api.example.com/translate', $request->url);
    }

    public function testDefaultValuesAreApplied(): void
    {
        $request = new HttpRequest(
            method: HttpMethod::GET,
            url: 'https://api.example.com/translate',
        );

        self::assertSame([], $request->headers);
        self::assertNull($request->body);
        self::assertSame(30, $request->timeoutSeconds);
    }

    public function testAllOptionalPropertiesCanBeSet(): void
    {
        $request = new HttpRequest(
            method: HttpMethod::POST,
            url: 'https://api.example.com/translate',
            headers: ['Authorization' => 'Bearer token123', 'Content-Type' => 'application/json'],
            body: '{"text":"Hello"}',
            timeoutSeconds: 60,
        );

        self::assertSame(HttpMethod::POST, $request->method);
        self::assertSame('https://api.example.com/translate', $request->url);
        self::assertSame(
            ['Authorization' => 'Bearer token123', 'Content-Type' => 'application/json'],
            $request->headers,
        );
        self::assertSame('{"text":"Hello"}', $request->body);
        self::assertSame(60, $request->timeoutSeconds);
    }
}
