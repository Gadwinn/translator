<?php

declare(strict_types=1);

namespace G\Translator\Exception\Bridge;

/**
 * Thrown when an AI provider rejects the request due to invalid,
 * missing, or revoked credentials.
 *
 * Typical triggers:
 * - HTTP 401 / 403 from the provider.
 * - Missing API key in the bridge configuration.
 * - Expired token.
 *
 * SHOULD be thrown distinctly from `AiProviderException` so that
 * consumers can implement credential-rotation logic without parsing
 * messages.
 */
final class AiAuthenticationException extends BridgeException
{
}
