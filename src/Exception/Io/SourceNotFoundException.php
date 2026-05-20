<?php

declare(strict_types=1);

namespace G\Translator\Exception\Io;

/**
 * Thrown when a requested input source cannot be located or accessed.
 *
 * Typical triggers:
 * - File does not exist at the given path.
 * - File exists but is not readable (permissions).
 * - Remote source returns 404 / connection refused.
 *
 * This exception SHOULD be thrown BEFORE attempting any parsing,
 * to avoid mixing "source unavailable" with "source invalid".
 */
final class SourceNotFoundException extends IoException
{
}
