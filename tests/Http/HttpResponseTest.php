<?php

declare(strict_types=1);

namespace G\Translator\Tests\Http;

use G\Translator\Http\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpResponse::class)]
final class HttpResponseTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $response = new HttpResponse(
            statusCode: 200,
            headers: ['content-type' => 'application/json'],
            body: '{"ok":true}',
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame(['content-type' => 'application/json'], $response->headers);
        self::assertSame('{"ok":true}', $response->body);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function successfulStatusCodeProvider(): array
    {
        return [
            '200 OK'             => [200, true],
            '201 Created'        => [201, true],
            '204 No Content'     => [204, true],
            '299 edge'           => [299, true],
            '300 Multiple'       => [300, false],
            '400 Bad Request'    => [400, false],
            '401 Unauthorized'   => [401, false],
            '429 Rate Limit'     => [429, false],
            '500 Internal Error' => [500, false],
        ];
    }

    #[DataProvider('successfulStatusCodeProvider')]
    public function testIsSuccessfulReturnsCorrectResult(int $statusCode, bool $expected): void
    {
        $response = new HttpResponse(
            statusCode: $statusCode,
            headers: [],
            body: '',
        );

        self::assertSame($expected, $response->isSuccessful());
    }
}
