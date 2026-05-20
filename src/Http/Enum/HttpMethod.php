<?php

declare(strict_types=1);

namespace G\Translator\Http\Enum;

/**
 * HTTP methods supported by the internal HTTP client.
 *
 * Intentionally restricted to the methods actually used by the
 * AI providers shipped with the package. Adding a new case is a
 * minor API change; removing one is a major.
 *
 * The string-backed value of each case is the canonical method
 * name in uppercase, suitable for direct use in raw HTTP headers
 * and curl options.
 */
enum HttpMethod: string
{
    case GET = 'GET';

    case POST = 'POST';

    case PUT = 'PUT';

    case DELETE = 'DELETE';

    /**
     * Indicates whether this method conventionally carries a
     * request body.
     *
     * Used by the HTTP client to decide whether the body should
     * be serialized and sent.
     */
    public function hasBody(): bool
    {
        return match ($this) {
            self::POST, self::PUT => true,
            self::GET, self::DELETE => false,
        };
    }
}
