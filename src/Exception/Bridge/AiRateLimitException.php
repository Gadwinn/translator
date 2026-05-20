<?php

declare(strict_types=1);

namespace G\Translator\Exception\Bridge;

/**
 * Thrown when an AI provider rejects the request because the consumer
 * has exceeded its rate limit or quota.
 *
 * Typical triggers:
 * - HTTP 429 from the provider.
 * - Quota / token budget exhausted for the billing period.
 *
 * SHOULD be thrown distinctly so that consumers can implement
 * backoff/retry strategies without inspecting error messages.
 * Implementations MAY expose retry-after metadata through
 * subsequent helper methods (out of scope for this base contract).
 */
final class AiRateLimitException extends BridgeException
{
}
