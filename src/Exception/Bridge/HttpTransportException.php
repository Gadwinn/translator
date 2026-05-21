<?php

declare(strict_types=1);

namespace G\Translator\Exception\Bridge;

/**
 * Thrown when the internal HTTP client fails at the transport layer.
 *
 * Typical triggers:
 *   - Connection refused / reset.
 *   - DNS resolution failure.
 *   - TLS handshake error.
 *   - Non-HTTPS URL while HTTPS-only mode is enforced.
 *
 * HTTP-level errors (4xx, 5xx, malformed bodies) are NOT this
 * exception's responsibility — they surface as ordinary
 * `HttpResponse` instances so the caller can inspect the status
 * code and body for provider-specific error details.
 */
class HttpTransportException extends BridgeException
{
}
