<?php

declare(strict_types=1);

namespace G\Translator\Exception\Bridge;

/**
 * Thrown when an AI provider returns an unexpected, malformed, or
 * error-state response.
 *
 * Typical triggers:
 * - Provider returned HTTP 5xx or a non-JSON payload when JSON was
 *   expected.
 * - Response schema is incompatible with the provider adapter.
 * - Provider-specific error code that does not map to a more specific
 *   exception (`AiAuthenticationException`, `AiRateLimitException`).
 */
final class AiProviderException extends BridgeException
{
}
