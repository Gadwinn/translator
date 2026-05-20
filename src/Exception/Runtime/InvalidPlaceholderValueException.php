<?php

declare(strict_types=1);

namespace G\Translator\Exception\Runtime;

/**
 * Thrown when a placeholder value cannot be safely rendered.
 *
 * Typical triggers:
 * - A value in `$params` is `null` (potential silent-bug source:
 *   often indicates an upstream variable that was not assigned).
 * - A value is a non-`Stringable` object, an array, or a
 *   resource — none of which can be coerced to a meaningful
 *   string representation.
 *
 * This exception is intentionally strict: the translator does NOT
 * silently render invalid values as empty strings, because doing
 * so would mask bugs at the call site. The caller MUST pass a
 * `scalar` or `Stringable` for every placeholder key referenced
 * by the translated message.
 */
final class InvalidPlaceholderValueException extends RuntimeException
{
}
