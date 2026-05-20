<?php

declare(strict_types=1);

namespace G\Translator\Exception\Io;

/**
 * Thrown when a parser fails to interpret an input source.
 *
 * Typical triggers:
 * - Malformed file content (invalid PO, broken XML, JSON syntax error…).
 * - Unexpected encoding or charset mismatch.
 * - Required fields/headers missing in the parsed payload.
 *
 * Implementations of `ParserInterface` MUST throw this exception (and only
 * this one within the I/O domain) when they cannot produce a valid
 * `TranslationBag` from the given source.
 */
final class ParserException extends IoException
{
}
