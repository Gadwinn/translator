<?php

declare(strict_types=1);

namespace G\Translator\Exception\Runtime;

/**
 * Thrown when a locale string is malformed or does not satisfy the
 * expected BCP-47 / RFC 5646 grammar.
 *
 * Typical triggers:
 * - Empty locale string.
 * - Invalid characters (spaces, non-ASCII, separators other than
 *   `-` or `_`).
 * - Region/script subtags that violate length constraints.
 *
 * Implementations MAY accept the underscore form (`fr_FR`) and normalize
 * it internally to the hyphen form (`fr-FR`), but MUST reject strings
 * that cannot be normalized to a valid BCP-47 tag.
 */
final class InvalidLocaleException extends RuntimeException
{
}
