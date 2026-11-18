<?php

declare(strict_types=1);

namespace G\Translator\Exception\Bridge;

/**
 * Thrown when the internal HTTP client gives up on a request because
 * the configured timeout was exceeded.
 *
 * Specializes `HttpTransportException` for the timeout case so that
 * consumers writing retry / backoff logic can distinguish a timeout
 * (typically transient, retryable) from a connection-refused or DNS
 * failure (often permanent, not retryable).
 *
 * Mapped from curl's `CURLE_OPERATION_TIMEDOUT` by `CurlHttpClient`.
 */
final class HttpTimeoutException extends HttpTransportException
{
}
