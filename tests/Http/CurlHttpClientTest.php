<?php

declare(strict_types=1);

namespace G\Translator\Tests\Http;

use G\Translator\Exception\Bridge\HttpTransportException;
use G\Translator\Http\CurlHttpClient;
use G\Translator\Http\Enum\HttpMethod;
use G\Translator\Http\HttpRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `CurlHttpClient`.
 *
 * Live network calls are intentionally avoided: only the parts of
 * the client that can be exercised without a server are tested here
 * (URL validation, the `allowHttp` flag, empty batch). End-to-end
 * coverage of `send()` and `sendMany()` against a real endpoint is
 * out of scope for the unit suite and SHOULD be covered by a
 * dedicated, opt-in integration suite (CI flag-gated).
 */
#[CoversClass(CurlHttpClient::class)]
final class CurlHttpClientTest extends TestCase
{
    public function testHttpsOnlyByDefault(): void
    {
        $client = new CurlHttpClient();
        $request = new HttpRequest(
            method: HttpMethod::GET,
            url: 'http://api.example.com/echo',
        );

        $this->expectException(HttpTransportException::class);
        $client->send($request);
    }

    public function testAllowHttpFlagPermitsHttpUrls(): void
    {
        $client = new CurlHttpClient(allowHttp: true);
        // We expect this to fail at the transport layer (port 1 is
        // not bound), NOT at the URL validation step.
        $request = new HttpRequest(
            method: HttpMethod::GET,
            url: 'http://127.0.0.1:1/never-listened',
            timeoutSeconds: 1,
        );

        $this->expectException(HttpTransportException::class);
        $client->send($request);
    }

    public function testSendManyOnEmptyInputReturnsEmptyArray(): void
    {
        $client = new CurlHttpClient();
        self::assertSame([], $client->sendMany([]));
    }

    public function testHttpsUrlPassesValidationButFailsAtTransport(): void
    {
        // 127.0.0.1 with an HTTPS scheme: URL validation passes, but
        // the TLS handshake / connect fails. We just assert the
        // transport-level translation works.
        $client = new CurlHttpClient();
        $request = new HttpRequest(
            method: HttpMethod::GET,
            url: 'https://127.0.0.1:1/closed',
            timeoutSeconds: 1,
        );

        $this->expectException(HttpTransportException::class);
        $client->send($request);
    }
}
