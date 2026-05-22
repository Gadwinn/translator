<?php

declare(strict_types=1);

namespace G\Translator\Http;

/**
 * Immutable representation of an HTTP response received by the
 * package's internal HTTP client.
 *
 * Provider implementations are expected to inspect `statusCode`
 * for success/failure (typically `>= 200 && < 300`) and decode
 * `body` as JSON when applicable. Parsing is the provider's
 * responsibility; the HTTP client only transports bytes.
 *
 * @see \G\Translator\Http\HttpClientInterface
 */
final readonly class HttpResponse
{
    /**
     * @var int Lower bound (inclusive) of the HTTP 2xx success range.
     */
    private const int HTTP_SUCCESS_RANGE_MIN = 200;

    /**
     * @var int Upper bound (exclusive) of the HTTP 2xx success range.
     */
    private const int HTTP_SUCCESS_RANGE_MAX = 300;

    /**
     * @param int $statusCode The HTTP status code returned by the
     *        remote server (e.g., 200, 401, 429, 500).
     * @param array<non-empty-string, string> $headers Response
     *        headers keyed by name (lowercased by the client to
     *        ease case-insensitive lookups).
     * @param string $body The raw response body as a string. MAY
     *        be empty.
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {
    }

    /**
     * Convenience helper: indicates whether the response carries
     * a successful 2xx status code.
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= self::HTTP_SUCCESS_RANGE_MIN
            && $this->statusCode < self::HTTP_SUCCESS_RANGE_MAX;
    }
}
