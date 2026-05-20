<?php

declare(strict_types=1);

namespace G\Translator\Http;

use G\Translator\Http\Enum\HttpMethod;

/**
 * Immutable representation of an HTTP request issued by the
 * package's internal HTTP client.
 *
 * Intentionally minimal: the package issues only JSON-over-HTTPS
 * calls to remote AI providers, so we do NOT need multipart
 * uploads, streaming bodies, redirects, cookies, or any of the
 * complexity covered by PSR-7 / PSR-18.
 *
 * Consumers who want a full-featured HTTP stack MAY write an
 * adapter from this contract to Guzzle, Symfony HttpClient, or
 * any PSR-18 implementation — without the package itself
 * depending on those libraries.
 *
 * @see \G\Translator\Http\HttpClientInterface
 */
final readonly class HttpRequest
{
    /**
     * @param HttpMethod $method The HTTP method.
     * @param non-empty-string $url The absolute target URL.
     * @param array<non-empty-string, string> $headers Headers
     *        keyed by name. Values are sent as-is (no folding,
     *        no comma-joining of duplicates).
     * @param string|null $body The raw request body, typically a
     *        JSON-encoded string. MUST be `null` when the method
     *        does not carry a body (see `HttpMethod::hasBody()`).
     * @param int $timeoutSeconds Maximum time (in seconds) the
     *        client may wait for the response. MUST be positive.
     *        Default: 30.
     */
    public function __construct(
        public HttpMethod $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
        public int $timeoutSeconds = 30,
    ) {
    }
}
