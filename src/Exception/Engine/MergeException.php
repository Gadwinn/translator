<?php

declare(strict_types=1);

namespace G\Translator\Exception\Engine;

/**
 * Thrown when a merge operation between two bags cannot be resolved.
 *
 * Typical triggers:
 * - Incompatible locales between merged bags (e.g., `fr-FR` vs `en-GB`)
 *   while the strategy requires locale equality.
 * - Custom strategy throws on a collision it cannot resolve.
 * - Structural conflict (e.g., one entry has plural forms, the other
 *   does not, and the strategy forbids implicit coercion).
 *
 * Strategies operating in tolerant mode (e.g., `REPLACE`, `KEEP_EXISTING`)
 * SHOULD NOT throw this exception for normal collisions; it is reserved
 * for genuinely irrecoverable conflicts.
 */
final class MergeException extends EngineException
{
}
