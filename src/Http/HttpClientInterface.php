<?php

declare(strict_types=1);

namespace G\Translator\Http;

use G\Translator\Exception\Bridge\BridgeException;

/**
 * Minimal contract for the package's internal HTTP client.
 *
 * Designed for one purpose only: issue JSON-over-HTTPS requests
 * to remote AI providers and return the raw response. Anything
 * more sophisticated (middlewares, streams, multipart, async)
 * is out of scope.
 *
 * The package ships a default curl-based implementation. Consumers
 * who already have an HTTP stack (Guzzle, Symfony HttpClient,
 * any PSR-18 client) MAY inject their own implementation by
 * writing a thin adapter — without forcing the package to depend
 * on those libraries.
 *
 * Implementations MUST:
 * - Be stateless across `send()` calls (no implicit cookie jar,
 *   no shared session state).
 * - Translate underlying transport errors (connection refused,
 *   timeout, DNS failure) into a subclass of `BridgeException`.
 *   Returning a synthetic `HttpResponse` with a fake status code
 *   is forbidden.
 * - Honor `HttpRequest::$timeoutSeconds` strictly.
 *
 * Parallel execution (e.g., `curl_multi_*`) is supported via
 * `sendMany()`; sequential implementations MAY implement it as
 * a simple loop over `send()`.
 *
 * @see \G\Translator\Http\HttpRequest
 * @see \G\Translator\Http\HttpResponse
 */
interface HttpClientInterface
{
    /**
     * Sends a single HTTP request and returns the response.
     *
     * @throws BridgeException When the request cannot be
     *         completed at the transport layer (connection
     *         refused, timeout, DNS failure, TLS error, etc.).
     *         HTTP-level errors (4xx, 5xx) are NOT thrown: they
     *         are returned as a normal `HttpResponse` so the
     *         caller can inspect the body for provider-specific
     *         error details.
     */
    public function send(HttpRequest $request): HttpResponse;

    /**
     * Sends multiple HTTP requests and returns their responses
     * in the same order.
     *
     * Implementations are STRONGLY encouraged to parallelize
     * (e.g., via `curl_multi_*`) to take advantage of the
     * concurrent throughput offered by providers when translating
     * many entries at once. A naive sequential implementation
     * remains compliant but defeats the purpose of this method.
     *
     * The returned array MUST have the same keys as the input
     * array, so callers can correlate responses with their
     * original requests.
     *
     * @param array<array-key, HttpRequest> $requests
     *
     * @return array<array-key, HttpResponse>
     *
     * @throws BridgeException When the batch cannot be issued at
     *         all (e.g., curl initialization failure). Per-request
     *         transport failures MUST be surfaced individually by
     *         throwing only after collecting all responses, or by
     *         a strategy documented by the implementation.
     */
    public function sendMany(array $requests): array;
}
