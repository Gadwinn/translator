<?php

declare(strict_types=1);

namespace G\Translator\Exception\Runtime;

/**
 * Thrown when the plural resolver cannot determine the appropriate
 * plural form for a given count and locale.
 *
 * Typical triggers:
 * - The locale has no registered plural rule and no CLDR fallback.
 * - A Gettext-style plural expression is malformed and cannot be
 *   evaluated.
 * - The selected plural category (e.g., `few`, `many`) has no matching
 *   entry in the message.
 */
final class PluralResolutionException extends RuntimeException
{
}
